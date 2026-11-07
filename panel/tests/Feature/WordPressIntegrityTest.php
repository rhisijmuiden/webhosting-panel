<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\Vulnerabilities\Models\Finding;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WpIntegrity\FakeResponses;
use Rhis\WpIntegrity\Models\IntegrityReport;
use Tests\TestCase;

class WordPressIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function install(string $domain = 'klant.nl', ?Customer $customer = null): WordPressInstall
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);

        return WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.6.2']);
    }

    private function clientOf(WordPressInstall $install): User
    {
        return User::factory()->create(['role' => 'client', 'customer_id' => $install->website->customer_id]);
    }

    public function test_nightly_check_stores_the_report_and_publishes_findings(): void
    {
        $install = $this->install();
        $this->artisan('wp-integrity:check')->assertSuccessful();

        $report = IntegrityReport::where('wordpress_install_id', $install->id)->firstOrFail();
        $this->assertSame('changed', $report->status);
        $this->assertSame(3, $report->changed_files);
        $this->assertSame(1, $report->malware_files);
        $this->assertSame(1, $report->unverifiable);

        $findings = Finding::where('kind', 'integrity')->orderBy('component_type')->get();
        $this->assertCount(2, $findings);
        $this->assertSame(['critical', 'medium'], $findings->pluck('severity')->all()); // core has a malware hit
        $this->assertSame('open', $findings[0]->status);
        $this->assertStringContainsString('wp-content/plugins/contact-form-7/includes/mail.php', $findings[1]->description);

        // A clean check resolves them.
        $clean = FakeResponses::report(['domain' => 'klant.nl']);
        foreach ($clean['components'] as &$c) {
            $c['files'] = [];
            $c['total_files'] = 0;
            $c['status'] = $c['status'] === 'changed' ? 'ok' : $c['status'];
        }
        unset($c);
        $this->agent()->respond('wpint.check', $clean);
        $this->artisan('wp-integrity:check')->assertSuccessful();
        $this->assertSame('ok', $report->fresh()->status);
        $this->assertSame(0, Finding::open()->where('kind', 'integrity')->count());
    }

    public function test_admin_overview_and_detail_page(): void
    {
        $install = $this->install();
        $this->artisan('wp-integrity:check')->assertSuccessful();
        $this->actingAsAdmin();

        $this->get('/admin/wordpress-integrity')->assertOk()
            ->assertSee('File integrity')->assertSee('klant.nl')->assertSee('Contact Form 7');
        $this->get("/admin/wordpress-integrity/{$install->id}")->assertOk()
            ->assertSee('wp-includes/functions.php')
            ->assertSee('Malware: PHP.Webshell.Generic')
            ->assertSee('wp-content/uploads/2026/10/cache.php')
            ->assertSee('Premium Builder')
            ->assertSee('Restore all original files');
        // The WordPress page links to it.
        $this->get("/admin/wordpress/{$install->id}")->assertOk()->assertSee(route('admin.wp-integrity.show', $install), false);
    }

    public function test_dutch_translation(): void
    {
        $install = $this->install();
        $this->artisan('wp-integrity:check')->assertSuccessful();
        $this->actingAsAdmin(User::factory()->admin()->create(['locale' => 'nl']));
        $this->withSession(['two_factor_passed' => true, 'locale' => 'nl'])
            ->get("/admin/wordpress-integrity/{$install->id}")->assertOk()
            ->assertSee('Bestandsintegriteit')->assertSee('Alle originele bestanden terugzetten');
    }

    public function test_restore_sends_only_open_files_and_stores_the_outcome(): void
    {
        $install = $this->install();
        $this->artisan('wp-integrity:check')->assertSuccessful();
        $this->actingAsAdmin();

        $this->post("/admin/wordpress-integrity/{$install->id}/restore", ['component' => 'core:wordpress', 'files' => ['wp-admin/css/colors/blue/admin.php', '../../etc/passwd']])
            ->assertRedirect("/admin/wordpress-integrity/{$install->id}");
        $call = collect($this->agent()->calls)->last(fn ($c) => $c['action'] === 'wpint.restore');
        $this->assertSame([['type' => 'core', 'slug' => 'wordpress', 'files' => ['wp-admin/css/colors/blue/admin.php'], 'quarantine_added' => true]], $call['params']['items']);
        $report = IntegrityReport::where('wordpress_install_id', $install->id)->first();
        $this->assertSame('restore', $report->job_kind);

        // The job finishes: the outcome and the new report are stored.
        $after = FakeResponses::report(['domain' => 'klant.nl']);
        $after['components'][0]['files'] = [$after['components'][0]['files'][0]];
        $after['components'][0]['total_files'] = 1;
        $this->agent()->respond('job.status', ['status' => 'succeeded', 'log' => ['Done'], 'error' => null,
            'result' => ['restored' => 0, 'quarantined' => 1, 'errors' => [], 'quarantine_dir' => 'quarantine/wordpress-integrity/20261107-101500', 'malware_rescanned' => false, 'report' => $after]]);
        $this->get("/admin/wordpress-integrity/{$install->id}")->assertOk()->assertSee('quarantine/wordpress-integrity/20261107-101500');
        $report->refresh();
        $this->assertNull($report->job_id);
        $this->assertSame(2, $report->changed_files);
        $this->assertSame(0, $report->malware_files);
        $this->assertSame(1, $report->last_restore['quarantined']);

        // "Restore all" sends every open file of every changed part.
        $this->post("/admin/wordpress-integrity/{$install->id}/restore", ['all' => 1])->assertRedirect();
        $call = collect($this->agent()->calls)->last(fn ($c) => $c['action'] === 'wpint.restore');
        $this->assertSame(['core', 'plugin'], array_column($call['params']['items'], 'type'));
    }

    public function test_accepting_a_change_removes_it_from_the_count(): void
    {
        $install = $this->install();
        $this->artisan('wp-integrity:check')->assertSuccessful();
        $this->actingAsAdmin();

        $this->post("/admin/wordpress-integrity/{$install->id}/accept", ['component' => 'plugin:contact-form-7', 'files' => ['includes/mail.php'], 'action' => 'accept'])
            ->assertRedirect()->assertSessionHas('status');
        $report = IntegrityReport::where('wordpress_install_id', $install->id)->first();
        $this->assertSame(2, $report->changed_files);
        $this->assertSame(0, Finding::open()->where('kind', 'integrity')->where('component_slug', 'contact-form-7')->count());

        // Malware can never be accepted.
        $this->post("/admin/wordpress-integrity/{$install->id}/accept", ['component' => 'core:wordpress', 'files' => ['wp-admin/css/colors/blue/admin.php'], 'action' => 'accept']);
        $this->assertSame(2, $report->fresh()->changed_files);

        // The same file with other contents counts again (accepted by md5).
        $this->post("/admin/wordpress-integrity/{$install->id}/accept", ['component' => 'plugin:contact-form-7', 'files' => ['includes/mail.php'], 'action' => 'undo']);
        $this->assertSame(3, $report->fresh()->changed_files);
    }

    public function test_customer_sees_own_sites_only_and_security_alert_links_here(): void
    {
        $install = $this->install();
        $other = $this->install('ander.nl');
        $this->artisan('wp-integrity:check')->assertSuccessful();
        $this->actingAs($this->clientOf($install));

        $this->get('/wordpress-integrity')->assertRedirect("/wordpress-integrity/{$install->id}");
        $this->get("/wordpress-integrity/{$install->id}")->assertOk()->assertSee('Contact Form 7');
        $this->get("/wordpress-integrity/{$other->id}")->assertNotFound();
        $this->post("/wordpress-integrity/{$other->id}/restore", ['all' => 1])->assertNotFound();

        $this->get('/security-alerts')->assertOk()
            ->assertSee('Changed files in WordPress core')
            ->assertSee(route('client.wp-integrity.show', $install), false)
            ->assertSee('Restore original files');

        config(['wp-integrity.customer' => false]);
        $this->get("/wordpress-integrity/{$install->id}")->assertNotFound();
    }

    public function test_check_now_starts_a_background_job(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->get("/admin/wordpress-integrity/{$install->id}")->assertOk()->assertSee('has not been checked yet');
        $this->post("/admin/wordpress-integrity/{$install->id}/check")->assertRedirect();
        $call = collect($this->agent()->calls)->last(fn ($c) => $c['action'] === 'wpint.check');
        $this->assertTrue($call['params']['background']);

        // The fake job.status has no report; the page asks wpint.report instead.
        $this->get("/admin/wordpress-integrity/{$install->id}")->assertOk()->assertSee('wp-includes/functions.php');
    }
}
