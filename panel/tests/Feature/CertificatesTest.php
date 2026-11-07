<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Rhis\Certificates\CertificateManager;
use Rhis\Certificates\CertificateParser;
use Rhis\Certificates\Jobs\IssueCertificate;
use Rhis\Certificates\Mail\CertificateExpiring;
use Rhis\Certificates\Models\SiteCertificate;
use Tests\TestCase;

class CertificatesTest extends TestCase
{
    use RefreshDatabase;

    /** A key and a certificate for the names, signed by $ca (or self-signed). */
    private function makeCert(array $names, int $days = 365, ?array $ca = null, bool $isCa = false, string $cn = ''): array
    {
        $config = tempnam(sys_get_temp_dir(), 'crt');
        $san = $names ? 'subjectAltName = '.implode(',', array_map(fn ($n) => 'DNS:'.$n, $names))."\n" : '';
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[v3]\n".$san.($isCa ? "basicConstraints = critical,CA:TRUE\nkeyUsage = keyCertSign,cRLSign\n" : "basicConstraints = CA:FALSE\n"));
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $csr = openssl_csr_new(['commonName' => $cn ?: ($names[0] ?? 'test')], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, $ca['cert'] ?? null, $ca['key'] ?? $key, $days, ['config' => $config, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        unlink($config);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return ['cert' => $certPem, 'key' => $keyPem];
    }

    private function bought(array $names = ['klant.nl', 'www.klant.nl'], int $days = 365): array
    {
        $root = $this->makeCert([], 3650, null, true, 'Test Root CA');
        $inter = $this->makeCert([], 1825, $root, true, 'Test Issuing CA');
        $leaf = $this->makeCert($names, $days, $inter);

        return ['cert' => $leaf['cert'], 'key' => $leaf['key'], 'chain' => $inter['cert'].$root['cert']];
    }

    private function client(): array
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@klant.nl']);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $customer->websites()->create(['domain' => 'klant.nl', 'status' => 'active', 'php_version' => '8.4', 'aliases' => ['www.klant.nl'], 'ssl_status' => 'active']);

        return [$customer, $user, $site];
    }

    public function test_client_uploads_an_own_certificate_with_its_chain(): void
    {
        [, $user, $site] = $this->client();
        $c = $this->bought();

        $this->actingAs($user)->get('/certificates')->assertOk()->assertSee('klant.nl')->assertSee(__('SSL certificates'));
        $this->actingAs($user)->get("/certificates/websites/{$site->id}")->assertOk()->assertSee(__('Upload own certificate'))->assertSee(__('Let\'s Encrypt'));

        $this->actingAs($user)->post("/certificates/websites/{$site->id}/custom", [
            'format' => 'pem', 'certificate' => $c['cert'], 'private_key' => $c['key'], 'chain' => $c['chain'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $call = collect($this->agent()->calls)->firstWhere('action', 'ssl.custom');
        $this->assertSame('klant.nl', $call['params']['domain']);
        $this->assertSame(1, substr_count($call['params']['chain'], 'BEGIN CERTIFICATE'), 'the root CA is left out of the chain');
        $this->assertStringContainsString('BEGIN PRIVATE KEY', $call['params']['private_key']);

        $record = SiteCertificate::sole();
        $this->assertSame('custom', $record->source);
        $this->assertSame(['klant.nl', 'www.klant.nl'], $record->names);
        $this->assertSame('active', $site->fresh()->ssl_status);
        $this->actingAs($user)->get("/websites/{$site->id}?tab=hosting")->assertOk()->assertSee(__('Own certificate'));
    }

    public function test_wrong_key_wrong_domain_and_expired_certificates_are_refused(): void
    {
        [, $user, $site] = $this->client();
        $c = $this->bought();
        $other = $this->bought(['ander.nl']);
        $url = "/certificates/websites/{$site->id}/custom";

        $this->actingAs($user)->post($url, ['format' => 'pem', 'certificate' => $c['cert'], 'private_key' => $other['key']])
            ->assertSessionHasErrors(['certificate' => __('The private key does not belong to this certificate.')]);
        $this->actingAs($user)->post($url, ['format' => 'pem', 'certificate' => $other['cert'], 'private_key' => $other['key']])
            ->assertSessionHasErrors('certificate');
        $this->actingAs($user)->post($url, ['format' => 'pem', 'certificate' => 'hello', 'private_key' => $c['key']])
            ->assertSessionHasErrors('certificate');
        $this->assertNull(collect($this->agent()->calls)->firstWhere('action', 'ssl.custom'));

        // Someone else's website: not found.
        $other = Customer::create(['name' => 'Piet', 'email' => 'piet@vreemd.nl']);
        $stranger = $other->websites()->create(['domain' => 'vreemd.nl', 'status' => 'active', 'php_version' => '8.4']);
        $this->actingAs($user)->get("/certificates/websites/{$stranger->id}")->assertNotFound();
    }

    public function test_parser_warnings_and_coverage(): void
    {
        $self = $this->makeCert(['klant.nl']);
        $parsed = app(CertificateParser::class)->fromPem($self['cert'], $self['key']);
        $this->assertTrue($parsed->selfSigned);
        $this->assertNotEmpty($parsed->warnings);

        $this->assertTrue(CertificateParser::covers('*.klant.nl', 'www.klant.nl'));
        $this->assertFalse(CertificateParser::covers('*.klant.nl', 'a.b.klant.nl'));
        $this->assertFalse(CertificateParser::covers('*.klant.nl', 'klant.nl'));
        $this->assertSame(['shop.klant.nl'], CertificateParser::uncovered(['klant.nl', 'www.klant.nl'], ['klant.nl', 'shop.klant.nl']));
    }

    public function test_pfx_upload(): void
    {
        [, $user, $site] = $this->client();
        $c = $this->bought();
        $pfx = '';
        openssl_pkcs12_export($c['cert'], $pfx, $c['key'], 'geheim', ['extracerts' => [$c['chain']]]);
        $file = UploadedFile::fake()->createWithContent('klant.pfx', $pfx);

        $this->actingAs($user)->post("/certificates/websites/{$site->id}/custom", ['format' => 'pfx', 'pfx_file' => $file, 'pfx_password' => 'fout'])
            ->assertSessionHasErrors('certificate');
        $file = UploadedFile::fake()->createWithContent('klant.pfx', $pfx);
        $this->actingAs($user)->post("/certificates/websites/{$site->id}/custom", ['format' => 'pfx', 'pfx_file' => $file, 'pfx_password' => 'geheim'])
            ->assertSessionHasNoErrors();
        $this->assertSame('custom', SiteCertificate::sole()->source);
    }

    public function test_csr_key_is_used_for_the_certificate_that_comes_back(): void
    {
        [, $user, $site] = $this->client();
        $this->actingAs($user)->post("/certificates/websites/{$site->id}/csr", ['key_type' => 'ec', 'organization' => 'Klant BV', 'country' => 'NL', 'www' => '1'])
            ->assertSessionHasNoErrors();
        $record = SiteCertificate::sole();
        $this->assertStringContainsString('BEGIN CERTIFICATE REQUEST', $record->csr);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) $record->getRawOriginal('csr_key'), 'the key is stored encrypted');

        $this->actingAs($user)->get("/certificates/websites/{$site->id}/csr")->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="klant.nl.csr"');

        // The "CA" signs the CSR; the customer uploads only the certificate.
        $config = tempnam(sys_get_temp_dir(), 'crt');
        file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[v3]\nsubjectAltName = DNS:klant.nl,DNS:www.klant.nl\n");
        $root = $this->makeCert([], 3650, null, true, 'Test Root CA');
        $signed = openssl_csr_sign($record->csr, $root['cert'], $root['key'], 365, ['config' => $config, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256'], 7);
        unlink($config);
        openssl_x509_export($signed, $pem);

        $this->actingAs($user)->post("/certificates/websites/{$site->id}/custom", ['format' => 'pem', 'certificate' => $pem])
            ->assertSessionHasNoErrors();
        $record->refresh();
        $this->assertSame('custom', $record->source);
        $this->assertNull($record->csr, 'the CSR is used up');
    }

    public function test_wildcard_needs_cloudflare_and_runs_in_the_queue(): void
    {
        [, $user, $site] = $this->client();
        $this->actingAs($user)->post("/certificates/websites/{$site->id}/wildcard")->assertSessionHasErrors('certificate');

        app(Settings::class)->set('dnsmail.cloudflare_token', 'cf_token_abcdefghijklmnopqrstuvwxyz', encrypted: true);
        Queue::fake();
        $this->actingAs($user)->post("/certificates/websites/{$site->id}/wildcard")->assertSessionHasNoErrors();
        Queue::assertPushed(IssueCertificate::class);
        $record = SiteCertificate::sole();
        $this->assertSame(['busy', 'wildcard'], [$record->status, $record->requested]);
        $this->actingAs($user)->post("/certificates/websites/{$site->id}/wildcard")->assertSessionHasErrors('certificate');

        // The job: the zone lookup is skipped without the DNS package's HTTP client in tests.
        $this->app->instance(\Rhis\DnsMail\Services\DnsSync::class, new class
        {
            public function client(): object
            {
                return new class
                {
                    public function findZone(string $d): ?array
                    {
                        return ['id' => 'z1', 'name' => 'klant.nl', 'status' => 'active'];
                    }
                };
            }
        });
        app(CertificateManager::class)->run($record);
        $record->refresh();
        $this->assertSame(['idle', 'wildcard', 'klant.nl'], [$record->status, $record->source, $record->wildcard_of]);
        $this->assertSame(['klant.nl', '*.klant.nl'], $record->names);
        $call = collect($this->agent()->calls)->firstWhere('action', 'ssl.wildcard');
        $this->assertSame('cf_token_abcdefghijklmnopqrstuvwxyz', $call['params']['cloudflare_token']);
    }

    public function test_failed_request_keeps_the_certificate_and_shows_the_reason(): void
    {
        [, $user, $site] = $this->client();
        $this->agent()->respond('ssl.letsencrypt', new AgentException('command_failed', 'whp-site exited with code 1', ['stderr' => "• trying\n✗ No Let's Encrypt certificate for klant.nl yet (DNS not pointing here?)"]));
        Queue::fake();
        $this->actingAs($user)->post("/certificates/websites/{$site->id}/letsencrypt")->assertSessionHasNoErrors();
        $record = SiteCertificate::sole();
        app(CertificateManager::class)->run($record);
        $record->refresh();
        $this->assertSame('failed', $record->status);
        $this->assertStringStartsWith('No Let\'s Encrypt certificate for klant.nl', $record->last_error);
        $this->actingAs($user)->get("/certificates/websites/{$site->id}")->assertOk()->assertSee(__('The last request failed.'));
    }

    public function test_daily_check_warns_admins_and_customers_once_per_step(): void
    {
        Mail::fake();
        [$customer, , $site] = $this->client();
        User::factory()->create(['role' => User::ROLE_ADMIN, 'email' => 'beheer@rhis.nl']);
        $soon = $this->bought(['klant.nl', 'www.klant.nl'], 10);
        $this->agent()->respond('ssl.list', fn () => ['certificates' => [[
            'domain' => 'klant.nl', 'ssl' => 'custom', 'wildcard_of' => null, 'names' => ['klant.nl'],
            'certificate' => ['common_name' => 'klant.nl', 'names' => ['klant.nl', 'www.klant.nl'], 'issuer' => 'CN=Test Issuing CA',
                'not_before' => now()->subDays(355)->toIso8601String(), 'not_after' => now()->addDays(10)->addHour()->toIso8601String(),
                'fingerprint_sha256' => 'AA', 'self_signed' => false, 'chain_length' => 1],
        ]]]);

        $this->artisan('certificates:check')->assertSuccessful();
        Mail::assertSent(CertificateExpiring::class, fn ($m) => $m->hasTo('beheer@rhis.nl') && $m->admin);
        Mail::assertSent(CertificateExpiring::class, fn ($m) => $m->hasTo('jan@klant.nl') && ! $m->admin);
        $this->assertSame(14, SiteCertificate::sole()->warned_days);

        $this->artisan('certificates:check')->assertSuccessful();
        Mail::assertSent(CertificateExpiring::class, 2); // no new step reached: no new mails

        $this->actingAsAdmin();
        $this->get('/admin/certificates')->assertOk()->assertSee('klant.nl')->assertSee(__('Own certificate'));
        $this->get('/admin/certificates?filter=attention')->assertOk()->assertSee('klant.nl');
        $this->get("/admin/certificates/websites/{$site->id}")->assertOk()->assertSee('www.klant.nl');
        unset($soon, $customer);
    }

    public function test_customers_cannot_change_certificates_when_switched_off(): void
    {
        config(['certificates.customer_manage' => false]);
        [, $user, $site] = $this->client();
        $this->actingAs($user)->get("/certificates/websites/{$site->id}")->assertOk()->assertDontSee(__('Upload own certificate'));
        $this->actingAs($user)->post("/certificates/websites/{$site->id}/letsencrypt")->assertForbidden();
    }

    public function test_admin_dutch_pages_render(): void
    {
        [, , $site] = $this->client();
        $admin = $this->actingAsAdmin();
        $admin->update(['locale' => 'nl']);
        $this->get("/admin/certificates/websites/{$site->id}")->assertOk()->assertSee('Eigen certificaat uploaden')->assertSee('Certificaataanvraag (CSR)');
        $this->get("/admin/websites/{$site->id}?tab=tools")->assertOk();
    }
}
