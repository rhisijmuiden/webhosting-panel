<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Rhis\Subdomains\Models\SiteClone;
use Rhis\Subdomains\Staging\Models\Staging;
use Rhis\Subdomains\Staging\Models\StagingPush;
use Rhis\WordPress\Models\WordPressInstall;
use Tests\TestCase;

class WordPressStagingTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $domain = 'klant.nl', string $email = 'jan@klant.nl'): array
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => $email]);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $customer->websites()->create(['domain' => $domain, 'status' => 'active', 'php_version' => '8.3', 'profile' => 'wordpress', 'aliases' => ['www.'.$domain]]);
        WordPressInstall::create(['website_id' => $site->id, 'path' => '', 'version' => '6.8.3']);

        return [$customer, $user, $site];
    }

    private function staging(User $user, Website $site): Staging
    {
        $this->actingAs($user)->post("/websites/{$site->id}/staging", ['label' => 'staging', 'path' => ''])
            ->assertRedirect()->assertSessionHasNoErrors();

        return Staging::sole();
    }

    private function calls(string $action): array
    {
        return collect($this->agent()->calls)->where('action', $action)->values()->all();
    }

    public function test_client_makes_a_staging_copy_that_is_marked_as_staging(): void
    {
        [, $user, $site] = $this->customer();
        $this->actingAs($user)->get("/websites/{$site->id}?tab=wordpress")->assertOk()
            ->assertSee(__('Staging'))->assertSee(__('Make staging copy'))->assertSee("/websites/{$site->id}/staging", false);
        $this->actingAs($user)->get("/websites/{$site->id}/staging")->assertOk()
            ->assertSee(__('Staging for :domain', ['domain' => 'klant.nl']))->assertSee('.klant.nl');

        $staging = $this->staging($user, $site);
        $copy = Website::where('domain', 'staging.klant.nl')->sole();
        $this->assertSame([$site->id, $copy->id, 'staging.klant.nl', 'ready'], [$staging->live_website_id, $staging->staging_website_id, $staging->staging_domain, $staging->status]);
        $this->assertSame($site->id, $copy->parent_id, 'the copy is a subdomain of the same subscription');
        $clone = $this->calls('site.clone')[0]['params'];
        $this->assertSame(['source' => 'klant.nl', 'target' => 'staging.klant.nl', 'replace' => false, 'noindex' => true], $clone);
        $this->assertSame(['staging' => 'staging.klant.nl', 'path' => ''], $this->calls('wp.staging_prepare')[0]['params']);

        $this->actingAs($user)->get("/staging/{$staging->id}")->assertOk()
            ->assertSee(__('Push to :domain', ['domain' => 'klant.nl']))->assertSee('wp_wc_orders')
            ->assertSee(__('This is a shop. Orders and customers that came in on the live website since the copy was made would be lost, so their tables are kept by default.'));
        // The copy's own page points at the push, and its staging page leads to the same staging.
        WordPressInstall::create(['website_id' => $copy->id, 'path' => '', 'version' => '6.8.3']);
        $this->actingAs($user)->get("/websites/{$copy->id}?tab=wordpress")->assertOk()
            ->assertSee(__('This website is the staging copy of :domain. Email from it is held back and search engines skip it.', ['domain' => 'klant.nl']));
        $this->actingAs($user)->get("/websites/{$copy->id}/staging")->assertRedirect("/staging/{$staging->id}");
    }

    public function test_push_needs_confirmation_and_sends_the_chosen_options(): void
    {
        [, $user, $site] = $this->customer();
        $staging = $this->staging($user, $site);

        $this->actingAs($user)->post("/staging/{$staging->id}/push", ['files' => '1', 'database' => '1'])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->post("/staging/{$staging->id}/push", ['files' => '1', 'database' => '1', 'confirm' => 'staging.klant.nl'])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->post("/staging/{$staging->id}/push", ['files' => '0', 'database' => '0', 'confirm' => 'klant.nl'])->assertSessionHasErrors('push');
        $this->assertSame([], $this->calls('wp.staging_push'));

        $this->agent()->respond('job.status', fn (array $p) => ['id' => $p['job'], 'status' => 'running', 'log' => ['backup of klant.nl: files', 'files: a -> b'], 'result' => null, 'error' => null]);
        $this->actingAs($user)->post("/staging/{$staging->id}/push", [
            'files' => '1', 'database' => '1', 'keep_uploads' => '1', 'confirm' => 'klant.nl',
            'keep_tables' => ['wp_wc_orders', 'wp_users', 'not_a_staging_table'],
        ])->assertRedirect("/staging/{$staging->id}")->assertSessionHasNoErrors();
        $params = $this->calls('wp.staging_push')[0]['params'];
        $this->assertSame(['staging' => 'staging.klant.nl', 'live' => 'klant.nl', 'path' => '', 'files' => true, 'database' => true,
            'keep_uploads' => true, 'keep_tables' => ['wp_wc_orders', 'wp_users']], $params);

        $push = StagingPush::sole();
        $this->assertSame(['push', 'running'], [$push->action, $push->status]);
        $this->actingAs($user)->get("/staging/{$staging->id}")->assertOk()->assertSee(__('Backup of the live website'))->assertDontSee('backup of klant.nl: files');
        // A second push while one runs is refused.
        $this->actingAs($user)->post("/staging/{$staging->id}/push", ['files' => '1', 'database' => '1', 'confirm' => 'klant.nl'])->assertSessionHasErrors('push');

        $this->agent()->respond('job.status', fn (array $p) => ['id' => $p['job'], 'status' => 'succeeded', 'log' => ['done'], 'error' => null,
            'result' => ['backup' => '20261107-021500', 'files' => true, 'database' => true, 'replacements' => 41, 'health' => 200]]);
        $this->artisan('wp-staging:tick')->assertSuccessful();
        $push->refresh();
        $this->assertSame(['succeeded', '20261107-021500'], [$push->status, $push->backup]);
        $this->assertNotNull($staging->fresh()->last_push_at);
        $this->actingAs($user)->get("/staging/{$staging->id}")->assertOk()->assertSee(__(':domain is live.', ['domain' => 'klant.nl']));
    }

    public function test_a_failed_push_shows_that_live_was_put_back_and_a_backup_can_be_restored(): void
    {
        [, $user, $site] = $this->customer();
        $staging = $this->staging($user, $site);
        $this->agent()->respond('job.status', fn (array $p) => ['id' => $p['job'], 'status' => 'failed', 'log' => [], 'result' => null,
            'error' => ['code' => 'command_failed', 'message' => 'The live website answered HTTP 500 after the push. The live website was put back as it was before the push.',
                'details' => ['backup' => '20261107-021500', 'rolled_back' => true]]]);
        $this->actingAs($user)->post("/staging/{$staging->id}/push", ['files' => '1', 'database' => '1', 'confirm' => 'klant.nl'])->assertSessionHasNoErrors();
        $this->actingAs($user)->get("/staging/{$staging->id}")->assertOk()->assertSee(__('The push failed. The live website was put back as it was.'))->assertSee('HTTP 500');
        $this->assertTrue(StagingPush::sole()->rolled_back);

        $this->actingAs($user)->post("/staging/{$staging->id}/restore", ['backup' => '19990101-000000'])->assertSessionHasErrors('restore');
        $this->actingAs($user)->post("/staging/{$staging->id}/restore", ['backup' => '../x'])->assertSessionHasErrors('backup');
        $this->agent()->respond('job.status', fn (array $p) => ['id' => $p['job'], 'status' => 'succeeded', 'log' => [], 'result' => ['live' => 'klant.nl'], 'error' => null]);
        $this->actingAs($user)->post("/staging/{$staging->id}/restore", ['backup' => '20261107-021500'])->assertSessionHasNoErrors();
        $this->assertSame(['live' => 'klant.nl', 'backup' => '20261107-021500'], $this->calls('wp.staging_restore')[0]['params']);
        $this->actingAs($user)->get("/staging/{$staging->id}")->assertOk()->assertSee(__('The backup is back on :domain.', ['domain' => 'klant.nl']));
    }

    public function test_only_the_own_customer_reaches_a_staging_and_it_can_be_switched_off(): void
    {
        [, $user, $site] = $this->customer();
        [, $other, $foreign] = $this->customer('ander.nl', 'piet@ander.nl');
        $staging = $this->staging($user, $site);

        $this->actingAs($other)->get("/staging/{$staging->id}")->assertNotFound();
        $this->actingAs($other)->post("/staging/{$staging->id}/push", ['files' => '1', 'confirm' => 'klant.nl'])->assertNotFound();
        $this->actingAs($user)->get("/websites/{$foreign->id}/staging")->assertNotFound();

        config(['subdomains.customer_staging' => false]);
        $this->actingAs($user)->get("/staging/{$staging->id}")->assertForbidden();
        $this->actingAs($user)->get("/websites/{$site->id}?tab=wordpress")->assertOk()->assertDontSee(__('Make staging copy'));
    }

    public function test_a_staging_copy_cannot_get_its_own_staging_and_a_failed_plan_blocks_the_push(): void
    {
        [, $user, $site] = $this->customer();
        $staging = $this->staging($user, $site);
        $copy = $staging->staging;
        $this->actingAs($user)->post("/websites/{$copy->id}/staging", ['label' => 'twee'])->assertSessionHasErrors('label');

        $this->agent()->respond('wp.staging_plan', new AgentException('conflict', 'the table prefixes differ (staging x_, live wp_)'));
        $this->actingAs($user)->get("/staging/{$staging->id}")->assertOk()->assertSee('the table prefixes differ');
        $this->actingAs($user)->post("/staging/{$staging->id}/push", ['files' => '1', 'database' => '1', 'confirm' => 'klant.nl'])->assertSessionHasErrors('push');
        $this->assertSame([], $this->calls('wp.staging_push'));
    }

    public function test_refresh_copies_live_again_and_remove_deletes_the_subdomain(): void
    {
        [, $user, $site] = $this->customer();
        $staging = $this->staging($user, $site);

        $this->actingAs($user)->post("/staging/{$staging->id}/refresh", ['confirm' => 'klant.nl'])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->post("/staging/{$staging->id}/refresh", ['confirm' => 'staging.klant.nl'])->assertSessionHasNoErrors();
        $this->assertSame(2, SiteClone::count());
        $this->assertSame(['source' => 'klant.nl', 'target' => 'staging.klant.nl', 'replace' => true, 'noindex' => true], $this->calls('site.clone')[1]['params']);
        $this->assertSame('ready', $staging->fresh()->status);

        $this->actingAs($user)->delete("/staging/{$staging->id}", ['remove_site' => '1', 'confirm' => 'klant.nl'])->assertSessionHasErrors('confirm');
        $this->actingAs($user)->delete("/staging/{$staging->id}", ['remove_site' => '1', 'confirm' => 'staging.klant.nl'])
            ->assertRedirect("/websites/{$site->id}?tab=wordpress");
        $this->assertSame(0, Staging::count());
        $this->assertFalse(Website::where('domain', 'staging.klant.nl')->exists());
        $this->assertTrue(Website::whereKey($site->id)->exists());
    }

    public function test_admin_manages_staging_with_the_job_log(): void
    {
        [, $user, $site] = $this->customer();
        $staging = $this->staging($user, $site);
        $this->actingAsAdmin();
        $this->get("/admin/websites/{$site->id}?tab=wordpress")->assertOk()->assertSee('staging.klant.nl')->assertSee("/admin/staging/{$staging->id}", false);
        $this->agent()->respond('job.status', fn (array $p) => ['id' => $p['job'], 'status' => 'running', 'log' => ['backup of klant.nl: files'], 'result' => null, 'error' => null]);
        $this->post("/admin/staging/{$staging->id}/push", ['files' => '1', 'database' => '0', 'confirm' => 'klant.nl'])->assertSessionHasNoErrors();
        $this->get("/admin/staging/{$staging->id}")->assertOk()->assertSee('backup of klant.nl: files');
    }
}
