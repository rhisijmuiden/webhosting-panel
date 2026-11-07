<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Rhis\ServerAdmin\Models\ServerTaskRun;
use Rhis\Vulnerabilities\Models\Finding;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\SmartUpdates\Mail\SmartUpdateReport;
use Rhis\WordPress\SmartUpdates\Models\UpdatePolicy;
use Rhis\WordPress\SmartUpdates\Models\UpdateRun;
use Rhis\WordPress\SmartUpdates\Options;
use Rhis\WordPress\SmartUpdates\SmartUpdater;
use Tests\TestCase;

class WordPressSmartUpdatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
    }

    private function calls(string $action): Collection
    {
        return collect($this->agent()->calls)->where('action', $action)->values();
    }

    private function install(string $domain = 'klant.nl', ?Customer $customer = null): WordPressInstall
    {
        $customer ??= Customer::create(['name' => 'Klant', 'email' => 'info@'.$domain, 'locale' => 'nl']);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active', 'profile' => 'wordpress', 'ssl_status' => 'active']);

        return WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.6.1', 'core_update' => '6.7.1',
            'outdated_plugins' => 2, 'outdated_themes' => 1, 'checked_at' => now()]);
    }

    /** Inside the server's nightly maintenance window (03:00-04:00, Europe/Amsterdam). */
    private function inWindow(string $time = '03:10', string $day = '2026-11-10'): void
    {
        $this->travelTo(Carbon::parse("$day $time", 'Europe/Amsterdam'));
    }

    private function client(Customer $customer): User
    {
        $user = User::factory()->create(['customer_id' => $customer->id, 'role' => 'client']);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    private function tick(): void
    {
        $this->artisan('whp:wordpress:smart-updates')->assertSuccessful();
    }

    private function finding(WordPressInstall $install, string $slug): void
    {
        Finding::create([
            'website_id' => $install->website_id, 'wordpress_install_id' => $install->id, 'kind' => 'vulnerability',
            'fingerprint' => hash('sha256', $slug), 'component_type' => 'plugin', 'component_slug' => $slug,
            'title' => 'XSS', 'severity' => 'high', 'status' => 'open',
        ]);
    }

    public function test_scheduled_update_runs_in_the_window_with_the_security_mode_and_is_kept(): void
    {
        $install = $this->install();
        $this->finding($install, 'contact-form-7');
        $this->inWindow();

        $this->tick();
        $call = $this->calls('wp.smart_update')->sole()['params'];
        $this->assertSame('klant.nl', $call['domain']);
        $this->assertSame('security', $call['mode']); // the panel default
        $this->assertSame(['plugins' => ['contact-form-7'], 'themes' => []], $call['security']);
        $this->assertSame(['/'], $call['pages']);
        $this->assertSame(['desktop', 'mobile'], $call['viewports']);
        $this->assertEquals(10, $call['threshold']);
        $this->assertTrue($call['visual']);
        $run = UpdateRun::sole();
        $this->assertSame('running', $run->status);
        $this->assertSame($call['run'], $run->uid);

        // The next minute the finished job is stored, with the screenshots, and the customer gets a mail.
        $this->travel(1)->minutes();
        $this->tick();
        $run->refresh();
        $this->assertSame('updated', $run->status, (string) $run->error);
        $this->assertSame(1.6, $run->max_score);
        $this->assertCount(2, $run->changes());
        $this->assertCount(2, $run->pages);
        Storage::disk('local')->assertExists($run->imagePath('p0-desktop-diff.jpg'));
        $this->assertCount(6, $this->calls('wp.smart_image'));
        $this->assertSame($run->uid, $this->calls('wp.smart_forget')->sole()['params']['run']);
        $this->assertSame('succeeded', $install->refresh()->last_update_status);
        Mail::assertSent(SmartUpdateReport::class, fn ($m) => $m->hasTo('info@klant.nl') && $m->area === 'client' && $m->locale === 'nl');
        Mail::assertNotSent(SmartUpdateReport::class, fn ($m) => $m->area === 'admin');

        // Once per window: later ticks in the same window do not start it again.
        $this->travel(5)->minutes();
        $this->tick();
        $this->assertCount(1, $this->calls('wp.smart_update'));
        // The next night it runs again.
        $this->inWindow('03:10', '2026-11-11');
        $this->tick();
        $this->assertCount(2, $this->calls('wp.smart_update'));
    }

    public function test_nothing_starts_outside_the_window_at_its_start_or_end(): void
    {
        $this->install();
        foreach (['12:00', '02:59', '03:02', '03:50'] as $time) {
            $this->inWindow($time);
            $this->tick();
        }
        $this->assertCount(0, $this->calls('wp.smart_update'));
    }

    public function test_updates_wait_while_the_server_maintenance_is_busy(): void
    {
        $this->install();
        $this->inWindow();
        ServerTaskRun::create(['task' => 'maintenance', 'trigger' => 'window', 'status' => 'running', 'started_at' => now()]);
        $this->tick();
        $this->assertCount(0, $this->calls('wp.smart_update'));
    }

    public function test_own_window_when_server_management_has_none(): void
    {
        app(\Rhis\ServerAdmin\Options::class)->set('window_enabled', false);
        $this->install();
        $this->inWindow('03:10');
        $updater = app(SmartUpdater::class);
        $this->assertSame('own', $updater->window()['source']);
        $this->assertSame('02:00 – 05:00', $updater->windowLabel());
        $this->tick();
        $this->assertCount(1, $this->calls('wp.smart_update'));
    }

    public function test_concurrency_and_off_mode(): void
    {
        $a = $this->install('a.nl');
        $b = $this->install('b.nl');
        $c = $this->install('c.nl');
        UpdatePolicy::for($c)->fill(['mode' => 'off'])->save();
        app(Options::class)->set('concurrency', 1);
        $this->agent()->respond('job.status', ['status' => 'running', 'log' => [], 'result' => null, 'error' => null]);
        $this->inWindow();

        $this->tick();
        $this->travel(1)->minutes();
        $this->tick();
        $this->assertCount(1, $this->calls('wp.smart_update')); // one at a time
        UpdateRun::query()->update(['status' => 'updated']);
        $this->tick();
        $this->assertSame(['a.nl', 'b.nl'], $this->calls('wp.smart_update')->pluck('params.domain')->sort()->values()->all());
    }

    public function test_visual_difference_is_rolled_back_and_admins_hear_about_it(): void
    {
        User::factory()->admin()->create(['email' => 'beheer@rhis.nl']);
        $install = $this->install('shop.nl');
        $this->inWindow();
        $this->tick();
        $this->travel(1)->minutes();
        $this->tick();

        $run = UpdateRun::sole();
        $this->assertSame('rolled_back', $run->status);
        $this->assertSame('visual', $run->reason);
        $this->assertSame(37.4, $run->max_score);
        $this->assertSame('rolled_back', $install->refresh()->last_update_status);
        Mail::assertSent(SmartUpdateReport::class, fn ($m) => $m->hasTo('info@shop.nl'));
        Mail::assertSent(SmartUpdateReport::class, fn ($m) => $m->hasTo('beheer@rhis.nl') && $m->area === 'admin');
        $mail = (new SmartUpdateReport($run, 'client'))->render();
        $this->assertStringContainsString('looked different after the update', $mail);
    }

    public function test_notify_only_mails_once_per_set_of_updates(): void
    {
        $install = $this->install();
        UpdatePolicy::for($install)->fill(['mode' => 'notify'])->save();
        $this->inWindow();
        $this->tick();
        $this->travel(1)->minutes();
        $this->tick();
        $run = UpdateRun::sole();
        $this->assertSame('notified', $run->status);
        $this->assertSame('notify', $this->calls('wp.smart_update')[0]['params']['mode']);
        Mail::assertSent(SmartUpdateReport::class, 1);

        // The same updates the next night: no new row, no new mail.
        $this->inWindow('03:10', '2026-11-11');
        $this->tick();
        $this->travel(1)->minutes();
        $this->tick();
        $this->assertSame(1, UpdateRun::count());
        Mail::assertSent(SmartUpdateReport::class, 1);
    }

    public function test_nothing_to_update_leaves_no_history(): void
    {
        $this->install();
        $this->agent()->respond('job.status', ['status' => 'succeeded', 'log' => [], 'error' => null,
            'result' => ['outcome' => 'up_to_date', 'pending' => ['core' => null, 'plugins' => [], 'themes' => []], 'pages' => []]]);
        $this->inWindow();
        $this->tick();
        $this->travel(1)->minutes();
        $this->tick();
        $this->assertSame(0, UpdateRun::count());
        $this->assertNotNull(UpdatePolicy::sole()->last_checked_at);
        Mail::assertNothingSent();
    }

    public function test_a_lost_job_is_marked_failed(): void
    {
        $this->install();
        $this->inWindow();
        $this->tick();
        $this->agent()->respond('job.status', new AgentException('not_found', 'Job not found'));
        $this->travel(1)->minutes();
        $this->tick();
        $run = UpdateRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('lost', $run->error);
    }

    public function test_a_failed_job_is_stored_with_its_error(): void
    {
        $this->install();
        $this->agent()->respond('job.status', ['status' => 'failed', 'log' => ['boom'], 'result' => null,
            'error' => ['message' => 'WordPress (wp core) failed: disk full', 'details' => []]]);
        $this->inWindow();
        $this->tick();
        $this->travel(1)->minutes();
        $this->tick();
        $run = UpdateRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertSame('WordPress (wp core) failed: disk full', $run->error);
    }

    public function test_admin_screens_render(): void
    {
        $this->actingAsAdmin();
        $install = $this->install('shop.nl');
        $run = app(SmartUpdater::class)->start($install);
        app(SmartUpdater::class)->follow($run);

        $this->get('/admin/wordpress/smart-updates')->assertOk()->assertSee('Smart updates')->assertSee('shop.nl')->assertSee('Security updates only');
        $this->get('/admin/wordpress/smart-updates?tab=history')->assertOk()->assertSee('Rolled back');
        $this->get('/admin/wordpress/smart-updates?tab=settings')->assertOk()->assertSee('Headless Chromium');
        $this->get("/admin/wordpress/smart-updates/sites/{$install->id}")->assertOk()->assertSee('Allowed visual difference');
        $this->get("/admin/wordpress/smart-updates/runs/{$run->uid}")->assertOk()->assertSee('Rolled back, the website kept working')
            ->assertSee("runs/{$run->uid}/images/p0-desktop-diff.jpg", false);
        $this->get("/admin/wordpress/smart-updates/runs/{$run->uid}/images/p0-desktop-diff.jpg")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get("/admin/wordpress/smart-updates/runs/{$run->uid}/images/p9-desktop-diff.jpg")->assertNotFound();
        $this->get("/admin/wordpress/smart-updates/runs/{$run->id}")->assertNotFound(); // uid only
        $this->get('/admin/wordpress')->assertOk()->assertSee('/admin/wordpress/smart-updates', false);
    }

    public function test_dutch_screens_render(): void
    {
        $admin = $this->actingAsAdmin();
        $admin->update(['locale' => 'nl']);
        $install = $this->install();
        $run = app(SmartUpdater::class)->start($install);
        app(SmartUpdater::class)->follow($run);
        $this->get('/admin/wordpress/smart-updates')->assertOk()->assertSee('Slimme updates')->assertSee('Alleen beveiligingsupdates');
        $this->get("/admin/wordpress/smart-updates/runs/{$run->uid}")->assertOk()->assertSee('Bijgewerkt, de website ziet er hetzelfde uit');
    }

    public function test_manual_run_starts_now_and_shows_the_run(): void
    {
        $this->actingAsAdmin();
        $install = $this->install();
        $res = $this->post("/admin/wordpress/smart-updates/sites/{$install->id}/run");
        $run = UpdateRun::sole();
        $res->assertRedirect("/admin/wordpress/smart-updates/runs/{$run->uid}");
        $this->assertSame('manual', $run->trigger);
        $this->assertSame('all', $run->mode);
        // A second one while it runs is refused.
        $this->agent()->respond('job.status', ['status' => 'running', 'log' => [], 'result' => null, 'error' => null]);
        $this->post("/admin/wordpress/smart-updates/sites/{$install->id}/run")->assertSessionHasErrors('agent');
        $this->getJson("/admin/wordpress/smart-updates/runs/{$run->uid}/status")->assertJson(['status' => 'running']);
    }

    public function test_admin_saves_settings_and_a_site_policy(): void
    {
        $this->actingAsAdmin();
        $install = $this->install();
        $this->post('/admin/wordpress/smart-updates/settings', ['default_mode' => 'all', 'threshold' => 15, 'concurrency' => 3,
            'visual' => '1', 'customer_policy' => '1', 'admin_mail_to' => 'a@rhis.nl, b@rhis.nl'])->assertRedirect();
        $o = app(Options::class);
        $this->assertSame('all', $o->get('default_mode'));
        $this->assertSame(15, $o->get('threshold'));
        $this->assertFalse($o->get('admin_notify'));
        $this->assertSame(['a@rhis.nl', 'b@rhis.nl'], $o->adminRecipients());
        $this->post('/admin/wordpress/smart-updates/settings', ['default_mode' => 'all', 'threshold' => 15, 'concurrency' => 3,
            'admin_mail_to' => 'geen-adres'])->assertSessionHasErrors('admin_mail_to');

        $this->put("/admin/wordpress/smart-updates/sites/{$install->id}", ['mode' => 'security', 'visual_check' => '1', 'threshold' => 25,
            'pages' => "/contact\nshop\nhttps://klant.nl/over-ons\n/", 'mail' => 'problems'])->assertRedirect();
        $policy = UpdatePolicy::sole();
        $this->assertSame('security', $policy->mode);
        $this->assertSame(['/', '/contact', '/shop', '/over-ons'], $policy->pageList());
        $this->assertSame(25, $policy->effectiveThreshold());
        $this->put("/admin/wordpress/smart-updates/sites/{$install->id}", ['mode' => 'all', 'pages' => '/a?b=1', 'mail' => 'always'])
            ->assertSessionHasErrors('pages');
        $this->put("/admin/wordpress/smart-updates/sites/{$install->id}", ['mode' => 'default', 'mail' => 'always'])->assertRedirect();
        $this->assertTrue($policy->refresh()->usesDefault());
        $this->assertSame('all', $policy->effectiveMode());
    }

    public function test_bulk_change(): void
    {
        $this->actingAsAdmin();
        $a = $this->install('a.nl');
        $b = $this->install('b.nl');
        $this->post('/admin/wordpress/smart-updates/bulk', ['installs' => [$a->id, $b->id], 'mode' => 'notify'])->assertRedirect();
        $this->assertSame(['notify', 'notify'], UpdatePolicy::pluck('mode')->all());
    }

    public function test_customers_see_and_change_only_their_own_sites(): void
    {
        $mine = $this->install('mijn.nl');
        $other = $this->install('ander.nl');
        $theirs = app(SmartUpdater::class)->start($other);
        app(SmartUpdater::class)->follow($theirs);
        $this->client($mine->website->customer);

        $this->get('/wordpress/smart-updates')->assertOk()->assertSee('mijn.nl')->assertDontSee('ander.nl')->assertDontSee('?tab=settings', false);
        $this->get("/wordpress/smart-updates/sites/{$other->id}")->assertNotFound();
        $this->get("/wordpress/smart-updates/runs/{$theirs->uid}")->assertNotFound();
        $this->get("/wordpress/smart-updates/runs/{$theirs->uid}/images/p0-desktop-before.jpg")->assertNotFound();
        $this->put("/wordpress/smart-updates/sites/{$other->id}", ['mode' => 'off', 'mail' => 'never'])->assertNotFound();
        $this->post('/wordpress/smart-updates/bulk', ['installs' => [$other->id], 'mode' => 'off'])->assertRedirect();
        $this->assertSame(0, UpdatePolicy::where('wordpress_install_id', $other->id)->where('mode', 'off')->count());

        $this->put("/wordpress/smart-updates/sites/{$mine->id}", ['mode' => 'all', 'visual_check' => '1', 'mail' => 'always'])->assertRedirect();
        $this->assertSame('all', UpdatePolicy::for($mine)->mode);
        $this->post('/admin/wordpress/smart-updates/settings', ['default_mode' => 'off'])->assertForbidden();

        // The admin can take the choice away from customers.
        app(Options::class)->set('customer_policy', false);
        $this->get("/wordpress/smart-updates/sites/{$mine->id}")->assertOk()->assertSee('Your hosting provider manages this setting');
        $this->put("/wordpress/smart-updates/sites/{$mine->id}", ['mode' => 'off', 'mail' => 'never'])->assertForbidden();

        // The card on the WordPress tab of the domain page.
        $this->get("/websites/{$mine->website_id}?tab=wordpress")->assertOk()->assertSee('Smart updates')
            ->assertSee("/wordpress/smart-updates/sites/{$mine->id}", false);
    }

    public function test_old_runs_and_their_screenshots_are_pruned(): void
    {
        $install = $this->install();
        $run = app(SmartUpdater::class)->start($install);
        app(SmartUpdater::class)->follow($run);
        Storage::disk('local')->assertExists($run->imagePath('p0-desktop-before.jpg'));
        $this->travel(100)->days();
        $this->tick();
        $this->assertSame(0, UpdateRun::count());
        Storage::disk('local')->assertMissing($run->imagePath('p0-desktop-before.jpg'));
    }
}
