<?php

namespace Tests\Feature;

use App\Jobs\RemoveWebsite;
use App\Models\Customer;
use App\Models\DatabaseUser;
use App\Models\HostingPackage;
use App\Models\SiteDatabase;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use App\Services\Team\TeamAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DatabasesTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create(['name' => 'Bakkerij', 'email' => 'info@bakkerij.nl']);
        $this->site = Website::create(['customer_id' => $this->customer->id, 'domain' => 'bakkerij.nl', 'status' => 'active']);
    }

    private function calls(string $action): Collection
    {
        return collect($this->agent()->calls)->where('action', $action)->values();
    }

    private function client(?Customer $customer = null): User
    {
        $user = User::factory()->create(['customer_id' => ($customer ?? $this->customer)->id, 'role' => 'client']);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);

        return $user;
    }

    public function test_client_creates_a_database_and_sees_the_password_once(): void
    {
        $this->client();
        // One website: the overview goes straight to it.
        $this->get('/databases')->assertRedirect("/databases/websites/{$this->site->id}");
        $this->get("/databases/websites/{$this->site->id}")->assertOk()->assertSee('bakkerij_nl_')->assertSee('No databases yet');

        $this->post("/databases/websites/{$this->site->id}", ['name' => 'shop'])
            ->assertSessionHasNoErrors()->assertRedirect("/databases/websites/{$this->site->id}");
        $password = session('db_credentials')['password'];
        $this->assertSame(['domain' => 'bakkerij.nl', 'name' => 'shop'], $this->calls('db.create')->last()['params']);
        $db = SiteDatabase::firstOrFail();
        $this->assertSame(['bakkerij_nl_shop', $this->site->id, ['bakkerij_nl_shop'], 'panel'], [$db->name, $db->website_id, $db->users, $db->origin]);

        $page = $this->get("/databases/websites/{$this->site->id}")->assertOk()->assertSee('Save these details now')->assertSee('bakkerij_nl_shop');
        $this->assertSame(32, strlen($password));
        $page->assertSee($password);
        // Only once.
        $this->get("/databases/websites/{$this->site->id}")->assertOk()->assertDontSee($password)->assertDontSee('Save these details now');
        $this->assertDatabaseHas('activity_logs', ['action' => 'database.created', 'subject' => 'bakkerij_nl_shop', 'customer_id' => $this->customer->id]);
    }

    public function test_own_password_and_name_rules(): void
    {
        $this->client();
        $url = "/databases/websites/{$this->site->id}";
        $this->post($url, ['name' => 'Shop!'])->assertSessionHasErrorsIn('database', 'name');
        $this->post($url, ['name' => '_x'])->assertSessionHasErrorsIn('database', 'name');
        $this->post($url, ['name' => 'shop', 'password' => 'kort'])->assertSessionHasErrorsIn('database', 'password');
        $this->post($url, ['name' => 'shop', 'password' => "Twaalf tekens\nlang"])->assertSessionHasErrorsIn('database', 'password');
        $this->assertSame(0, SiteDatabase::count());

        $this->post($url, ['name' => 'shop', 'password' => 'Mijn eigen wachtwoord!'])->assertSessionHasNoErrors();
        $this->assertSame('Mijn eigen wachtwoord!', $this->calls('db.create')->last()['params']['password']);
        $this->get($url)->assertSee('The password you chose is set')->assertDontSee('Mijn eigen wachtwoord!');

        // The same name again: the agent refuses it.
        $this->post($url, ['name' => 'shop'])->assertSessionHasErrorsIn('database', 'name');
    }

    public function test_package_limit_counts_the_whole_subscription(): void
    {
        $this->withPackage($this->site, HostingPackage::create(['name' => 'Start', 'max_databases' => 2]));
        $sub = Website::create(['customer_id' => $this->customer->id, 'parent_id' => $this->site->id, 'domain' => 'test.bakkerij.nl', 'status' => 'active']);
        SiteDatabase::create(['website_id' => $sub->id, 'name' => 'test_bakkerij_nl_wp', 'users' => ['test_bakkerij_nl_wp'], 'origin' => 'app']);
        $this->client();

        $this->post("/databases/websites/{$this->site->id}", ['name' => 'one'])->assertSessionHasNoErrors();
        $this->post("/databases/websites/{$this->site->id}", ['name' => 'two'])->assertSessionHasErrorsIn('database', 'name');
        $this->post("/databases/websites/{$sub->id}", ['name' => 'three'])->assertSessionHasErrorsIn('database', 'name');
        $this->assertSame(2, SiteDatabase::count());
        $this->assertCount(1, $this->calls('db.create'));
        $this->get("/databases/websites/{$this->site->id}")->assertSee('All 2 databases of the package are in use');

        $this->withPackage($this->site, HostingPackage::create(['name' => 'Geen', 'max_databases' => 0]));
        $this->get("/databases/websites/{$this->site->id}")->assertSee('does not include databases');
    }

    public function test_extra_users_access_and_password_reset(): void
    {
        $this->client();
        $url = "/databases/websites/{$this->site->id}";
        $this->post($url, ['name' => 'shop']);
        $this->post($url, ['name' => 'blog']);

        $this->post("$url/users", ['name' => 'dev', 'databases' => ['bakkerij_nl_other']])->assertSessionHasErrorsIn('user', 'databases.0');
        $this->post("$url/users", ['name' => 'dev', 'databases' => []])->assertSessionHasErrorsIn('user', 'databases');
        $this->post("$url/users", ['name' => 'rapport', 'databases' => ['bakkerij_nl_shop', 'bakkerij_nl_blog'], 'readonly' => '1'])
            ->assertSessionHasNoErrors();
        $p = $this->calls('db.user_create')->last()['params'];
        $this->assertSame(['bakkerij.nl', 'rapport', true], [$p['domain'], $p['name'], $p['readonly']]);
        $user = DatabaseUser::firstOrFail();
        $this->assertSame(['bakkerij_nl_rapport', true], [$user->name, $user->readonly]);
        $this->get($url)->assertOk()->assertSee('bakkerij_nl_rapport')->assertSee('Read only');

        $this->put("/databases/users/{$user->id}", ['databases' => ['bakkerij_nl_blog'], 'readonly' => '0'])->assertSessionHasNoErrors();
        $this->assertSame([['bakkerij_nl_blog'], false], [$user->refresh()->databases, $user->readonly]);

        // Reset: main user and extra user, not someone else's.
        $this->post("$url/password", ['user' => 'bakkerij_nl_shop'])->assertSessionHasNoErrors();
        $this->assertSame('bakkerij_nl_shop', $this->calls('db.password')->last()['params']['user']);
        $this->post("$url/password", ['user' => 'bakkerij_nl_rapport', 'password' => 'Nieuw-wachtwoord-2026'])->assertSessionHasNoErrors();
        $this->post("$url/password", ['user' => 'root'])->assertSessionHasErrorsIn('password', 'user');
        $this->assertDatabaseHas('activity_logs', ['action' => 'database.password_reset', 'subject' => 'bakkerij_nl_rapport']);

        // Dropping its last database drops the extra user too.
        $blog = SiteDatabase::where('name', 'bakkerij_nl_blog')->firstOrFail();
        $this->delete("/databases/{$blog->id}", ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        $this->delete("/databases/{$blog->id}", ['confirm' => 'bakkerij_nl_blog'])->assertSessionHasNoErrors();
        $this->assertSame(['bakkerij_nl_shop'], SiteDatabase::pluck('name')->all());
        $this->assertSame(0, DatabaseUser::count());

        $this->post("$url/users", ['name' => 'dev', 'databases' => ['bakkerij_nl_shop']]);
        $dev = DatabaseUser::firstOrFail();
        $this->delete("/databases/users/{$dev->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, DatabaseUser::count());
        $this->assertSame('bakkerij_nl_dev', $this->calls('db.user_drop')->last()['params']['user']);
    }

    public function test_phpmyadmin_sign_on_redirects_with_a_one_time_token(): void
    {
        $this->client();
        $this->post("/databases/websites/{$this->site->id}", ['name' => 'shop']);
        $db = SiteDatabase::firstOrFail();
        $this->post("/databases/{$db->id}/phpmyadmin")
            ->assertRedirect(url('/phpmyadmin/whp-sso.php').'?token='.str_repeat('ab', 32));
        $this->assertSame(['domain' => 'bakkerij.nl', 'name' => 'bakkerij_nl_shop', 'lang' => 'en'], $this->calls('db.sso')->last()['params']);

        $this->agent()->respond('db.sso', new AgentException('unavailable', 'phpMyAdmin is not installed on this server'));
        $this->post("/databases/{$db->id}/phpmyadmin")->assertSessionHasErrors('agent');
    }

    public function test_customers_cannot_reach_other_customers_databases(): void
    {
        $other = Customer::create(['name' => 'Ander', 'email' => 'x@ander.nl']);
        $theirs = Website::create(['customer_id' => $other->id, 'domain' => 'ander.nl', 'status' => 'active']);
        $db = SiteDatabase::create(['website_id' => $theirs->id, 'name' => 'ander_nl_db', 'users' => ['ander_nl_db']]);
        $user = DatabaseUser::create(['website_id' => $theirs->id, 'name' => 'ander_nl_x', 'databases' => ['ander_nl_db']]);
        $this->client();

        $this->get("/databases/websites/{$theirs->id}")->assertNotFound();
        $this->post("/databases/websites/{$theirs->id}", ['name' => 'x'])->assertNotFound();
        $this->post("/databases/{$db->id}/phpmyadmin")->assertNotFound();
        $this->delete("/databases/{$db->id}", ['confirm' => 'ander_nl_db'])->assertNotFound();
        $this->delete("/databases/users/{$user->id}")->assertNotFound();
        $this->post("/databases/websites/{$theirs->id}/password", ['user' => 'ander_nl_db'])->assertNotFound();
        $this->assertCount(0, $this->calls('db.sso'));
        $this->assertCount(0, $this->calls('db.drop'));
    }

    public function test_team_members_need_the_databases_area(): void
    {
        $user = User::factory()->create(['customer_id' => $this->customer->id]);
        $member = TeamMember::create(['user_id' => $user->id, 'customer_id' => $this->customer->id, 'all_websites' => true, 'areas' => ['files']]);
        $this->actingAs($user)->withSession(['two_factor_passed' => true]);
        $this->get("/databases/websites/{$this->site->id}")->assertForbidden();

        $member->update(['areas' => ['databases']]);
        app(TeamAccess::class)->forget();
        $this->get("/databases/websites/{$this->site->id}")->assertOk();
    }

    public function test_migrated_databases_are_adopted_on_the_page(): void
    {
        SiteDatabase::create(['website_id' => $this->site->id, 'name' => 'oude_shop', 'users' => ['oude_user'], 'origin' => 'migration']);
        $this->client();
        $this->get("/databases/websites/{$this->site->id}")->assertOk()->assertSee('oude_shop')->assertSee('Migrated');
        $this->assertSame(['domain' => 'bakkerij.nl', 'name' => 'oude_shop', 'user' => 'oude_user'], $this->calls('db.adopt')->last()['params']);
    }

    public function test_admin_overview_search_and_management(): void
    {
        SiteDatabase::create(['website_id' => $this->site->id, 'name' => 'bakkerij_nl_shop', 'users' => ['bakkerij_nl_shop']]);
        Website::create(['customer_id' => $this->customer->id, 'domain' => 'leeg.nl', 'status' => 'active']);
        $this->actingAsAdmin();
        $this->get('/admin/databases')->assertOk()->assertSee('bakkerij.nl')->assertSee('leeg.nl')->assertSee('Bakkerij');
        $this->get('/admin/databases?with=1')->assertOk()->assertSee('bakkerij.nl')->assertDontSee('leeg.nl');
        $this->get('/admin/databases?q=shop')->assertOk()->assertSee('bakkerij.nl')->assertDontSee('leeg.nl');
        $this->get("/admin/databases/websites/{$this->site->id}")->assertOk()->assertSee('bakkerij_nl_shop')->assertSee('phpMyAdmin');

        $this->post("/admin/databases/websites/{$this->site->id}", ['name' => 'extra'])->assertSessionHasNoErrors();
        $this->assertSame(2, SiteDatabase::count());
    }

    public function test_menu_and_dutch(): void
    {
        $user = $this->client();
        $user->update(['locale' => 'nl']);
        $this->get("/databases/websites/{$this->site->id}")->assertOk()
            ->assertSee('Nieuwe database')->assertSee('Verbinden vanuit je website')->assertSee(route('client.databases.index'), false);
    }

    public function test_removing_a_website_drops_its_databases(): void
    {
        $this->client();
        $this->post("/databases/websites/{$this->site->id}", ['name' => 'shop']);
        $this->post("/databases/websites/{$this->site->id}/users", ['name' => 'dev', 'databases' => ['bakkerij_nl_shop']]);
        (new RemoveWebsite($this->site, false))->handle($this->agent());
        $this->assertSame('bakkerij_nl_shop', $this->calls('db.drop')->last()['params']['name']);
        $this->assertSame(0, SiteDatabase::count());
        $this->assertSame(0, DatabaseUser::count());
    }
}
