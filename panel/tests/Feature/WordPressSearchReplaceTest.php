<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\SearchReplace\SearchReplaceRun;
use Tests\TestCase;

class WordPressSearchReplaceTest extends TestCase
{
    use RefreshDatabase;

    private function install(string $domain = 'klant.nl', ?Customer $customer = null): WordPressInstall
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain]);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress',
            'ssl_status' => 'active', 'aliases' => ['www.'.$domain, 'oud-'.$domain]]);

        return WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.7.1']);
    }

    private function agentCall(string $action): ?array
    {
        return collect($this->agent()->calls)->where('action', $action)->last();
    }

    public function test_install_page_links_to_both_tools(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->get("/admin/wordpress/{$install->id}")->assertOk()
            ->assertSee(route('admin.wordpress.sr.domain', $install), false)
            ->assertSee(route('admin.wordpress.sr.form', $install), false)
            ->assertSee('Addresses and content');
    }

    public function test_search_replace_preview_then_apply_with_snapshot(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->get("/admin/wordpress/{$install->id}/search-replace")->assertOk()->assertSee('Search for')->assertSee('Show preview');

        $res = $this->post("/admin/wordpress/{$install->id}/search-replace", ['search' => 'Oud Bedrijf BV', 'replace' => 'Klant BV',
            'tables' => 'wordpress', 'skip_guid' => '1']);
        $run = SearchReplaceRun::sole();
        $res->assertRedirect(route('admin.wordpress.sr.show', $run));
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'mode' => 'text', 'tables' => 'wordpress', 'skip_guid' => true,
            'search' => 'Oud Bedrijf BV', 'replace' => 'Klant BV'], $this->agentCall('wp.sr_preview')['params']);
        $this->assertNull($this->agentCall('wp.sr_apply'));

        // The run page picks up the finished dry run.
        $this->get(route('admin.wordpress.sr.show', $run))->assertOk()
            ->assertSee('Would be replaced')->assertSee('53')->assertSee('wp_postmeta')->assertSee('Klant BV')
            ->assertSee('Replace everything')->assertSee(route('admin.wordpress.sr.apply', $run), false);
        $this->assertSame('previewed', $run->fresh()->status);

        $this->post(route('admin.wordpress.sr.apply', $run))->assertRedirect(route('admin.wordpress.sr.show', $run));
        $this->assertSame('applying', $run->fresh()->status);
        $apply = $this->agentCall('wp.sr_apply')['params'];
        $this->assertTrue($apply['snapshot']);
        $this->assertSame('Oud Bedrijf BV', $apply['search']);
        $this->assertSame('wordpress.search_replace', ActivityLog::latest('id')->first()->action);

        $this->get(route('admin.wordpress.sr.show', $run))->assertOk()->assertSee('53 replacements made.')
            ->assertSee(route('admin.wordpress.restore', $install), false);
        $this->assertSame('applied', $run->fresh()->status);

        // Applying twice is refused.
        $this->post(route('admin.wordpress.sr.apply', $run))->assertStatus(409);
        $this->get("/admin/wordpress/{$install->id}/search-replace")->assertSee('Earlier runs')->assertSee('Oud Bedrijf BV');
    }

    public function test_admin_may_skip_the_snapshot_customer_may_not(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $install = $this->install('klant.nl', $mine);
        $this->actingAsAdmin();
        $this->post("/admin/wordpress/{$install->id}/search-replace", ['search' => 'abcdef', 'replace' => 'x']);
        $run = SearchReplaceRun::latest('id')->first();
        $this->get(route('admin.wordpress.sr.show', $run));
        $this->post(route('admin.wordpress.sr.apply', $run), ['skip_snapshot' => '1']);
        $this->assertFalse($this->agentCall('wp.sr_apply')['params']['snapshot']);
        $this->get(route('admin.wordpress.sr.show', $run))->assertSee('53 replacements made.');

        $this->actingAs(User::factory()->create(['customer_id' => $mine->id]));
        $this->post("/wordpress/{$install->id}/search-replace", ['search' => 'abcdef', 'replace' => 'y']);
        $run = SearchReplaceRun::latest('id')->first();
        $this->assertSame('previewing', $run->status);
        $this->get(route('client.wordpress.sr.show', $run))->assertOk()->assertDontSee('Skip the snapshot');
        $this->post(route('client.wordpress.sr.apply', $run), ['skip_snapshot' => '1']);
        $this->assertTrue($this->agentCall('wp.sr_apply')['params']['snapshot']);
    }

    public function test_validation(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        foreach ([['search' => 'ab'], ['search' => '--url=x'], ['search' => 'same', 'replace' => 'same'], ['search' => 'abc', 'replace' => '-x'],
            ['search' => 'abc', 'tables' => 'mysql']] as $bad) {
            $this->post("/admin/wordpress/{$install->id}/search-replace", $bad)->assertSessionHasErrors();
        }
        $this->post("/admin/wordpress/{$install->id}/change-domain", ['host' => 'not a host', 'scheme' => 'https'])->assertSessionHasErrors('host');
        $this->post("/admin/wordpress/{$install->id}/change-domain", ['host' => 'klant.nl', 'scheme' => 'ftp'])->assertSessionHasErrors('scheme');
        $this->assertSame(0, SearchReplaceRun::count());
    }

    public function test_change_domain_wizard(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();

        $this->get("/admin/wordpress/{$install->id}/change-domain")->assertOk()
            ->assertSee('Current address')->assertSee('https://klant.nl')->assertSee('oud-klant.nl')->assertSee('SSL active')
            ->assertSee(route('admin.domain-aliases.index'), false);

        $this->post("/admin/wordpress/{$install->id}/change-domain", ['host' => 'Oud-Klant.nl', 'scheme' => 'https']);
        $run = SearchReplaceRun::sole();
        $this->assertSame('domain', $run->mode);
        $this->assertSame(['domain' => 'klant.nl', 'path' => '', 'mode' => 'domain', 'tables' => 'wordpress', 'skip_guid' => true,
            'host' => 'oud-klant.nl', 'scheme' => 'https'], $this->agentCall('wp.sr_preview')['params']);

        $this->get(route('admin.wordpress.sr.show', $run))->assertOk()->assertSee('https://oud-klant.nl')->assertSee('Change the domain');
        $this->post(route('admin.wordpress.sr.apply', $run));
        $this->get(route('admin.wordpress.sr.show', $run))->assertOk()->assertSee('WordPress now uses https://oud-klant.nl');
        $this->assertSame('wordpress.change_domain', ActivityLog::latest('id')->first()->action);
    }

    public function test_old_preview_must_be_done_again(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->post("/admin/wordpress/{$install->id}/search-replace", ['search' => 'abcdef', 'replace' => 'x']);
        $run = SearchReplaceRun::sole();
        $this->get(route('admin.wordpress.sr.show', $run));
        $run->update(['previewed_at' => now()->subHours(2)]);
        $this->get(route('admin.wordpress.sr.show', $run))->assertSee('This preview is too old');
        $this->post(route('admin.wordpress.sr.apply', $run))->assertSessionHasErrors('agent');
        $this->assertNull($this->agentCall('wp.sr_apply'));
    }

    public function test_failed_preview_and_rolled_back_apply_are_shown(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.sr_preview', ['job' => '11111111-1111-1111-1111-111111111111']);
        $this->agent()->respond('job.status', fn (array $p) => $p['job'] === '11111111-1111-1111-1111-111111111111'
            ? ['status' => 'failed', 'log' => [], 'result' => null, 'error' => ['code' => 'forbidden', 'message' => 'vreemd.nl is not served by klant.nl']]
            : ['status' => 'failed', 'log' => [], 'result' => null, 'error' => ['code' => 'command_failed', 'message' => 'The site broke',
                'details' => ['rolled_back' => true, 'health' => ['before' => 200, 'after' => 500], 'total' => 3, 'tables' => []]]]);

        $this->post("/admin/wordpress/{$install->id}/change-domain", ['host' => 'vreemd.nl', 'scheme' => 'https']);
        $run = SearchReplaceRun::sole();
        $this->get(route('admin.wordpress.sr.show', $run))->assertOk()->assertSee('The preview failed')->assertSee('vreemd.nl is not served');
        $this->assertSame('preview_failed', $run->fresh()->status);

        $run->update(['status' => 'applying', 'apply_job' => '22222222-2222-2222-2222-222222222222', 'preview' => ['total' => 3, 'tables' => []]]);
        $this->get(route('admin.wordpress.sr.show', $run))->assertOk()->assertSee('HTTP 500');
        $this->assertSame('rolled_back', $run->fresh()->status);
    }

    public function test_agent_down_on_preview_keeps_the_form(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        $this->agent()->respond('wp.sr_preview', new AgentException('unreachable', 'Agent not reachable'));
        $this->from("/admin/wordpress/{$install->id}/search-replace")
            ->post("/admin/wordpress/{$install->id}/search-replace", ['search' => 'abcdef', 'replace' => 'x'])
            ->assertRedirect("/admin/wordpress/{$install->id}/search-replace")->assertSessionHasErrors('agent');
        $this->assertSame(0, SearchReplaceRun::count());
    }

    public function test_customers_only_see_their_own_sites(): void
    {
        $mine = Customer::create(['name' => 'Mine', 'email' => 'mine@example.test']);
        $install = $this->install('mijn.nl', $mine);
        $other = $this->install('ander.nl');
        $this->actingAs(User::factory()->create(['customer_id' => $mine->id]));

        $this->get("/wordpress/{$install->id}/change-domain")->assertOk()->assertSee('mijn.nl');
        $this->get("/wordpress/{$install->id}/search-replace")->assertOk();
        $this->get("/wordpress/{$other->id}/change-domain")->assertNotFound();
        $this->post("/wordpress/{$other->id}/search-replace", ['search' => 'abcdef'])->assertNotFound();

        $this->actingAsAdmin();
        $this->post("/admin/wordpress/{$other->id}/search-replace", ['search' => 'abcdef', 'replace' => 'x']);
        $run = SearchReplaceRun::sole();
        $this->actingAs(User::factory()->create(['customer_id' => $mine->id]));
        $this->get(route('client.wordpress.sr.show', $run))->assertNotFound();
        $this->post(route('client.wordpress.sr.apply', $run))->assertNotFound();

        config(['wordpress.customer_toolkit' => false]);
        $this->get("/wordpress/{$install->id}/change-domain")->assertNotFound();
    }

    public function test_only_one_run_at_a_time(): void
    {
        $install = $this->install();
        $this->actingAsAdmin();
        SearchReplaceRun::create(['wordpress_install_id' => $install->id, 'mode' => 'text', 'params' => ['mode' => 'text', 'search' => 'x', 'replace' => 'y'],
            'status' => 'applying', 'apply_job' => '33333333-3333-3333-3333-333333333333']);
        $this->post("/admin/wordpress/{$install->id}/search-replace", ['search' => 'abcdef', 'replace' => 'x'])->assertSessionHasErrors('agent');
        $this->assertSame(1, SearchReplaceRun::count());
    }

    public function test_dutch_translations_are_complete(): void
    {
        $nl = json_decode(file_get_contents(base_path('../packages/wordpress/lang/search-replace/nl.json')), true);
        $files = [...glob(base_path('../packages/wordpress/resources/views/search-replace/*.php')), ...glob(base_path('../packages/wordpress/src/SearchReplace/*.php'))];
        foreach ($files as $file) {
            preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($file), $m);
            foreach ($m[1] as $key) {
                $key = str_replace("\\'", "'", $key);
                $this->assertArrayHasKey($key, $nl, basename($file).': '.$key);
                preg_match_all('/:[a-z_]+/', $key, $ph);
                foreach ($ph[0] as $placeholder) {
                    $this->assertStringContainsString($placeholder, $nl[$key], $key);
                }
            }
        }
        app()->setLocale('nl');
        $this->assertSame('Domein wijzigen', __('Change domain'));
    }
}
