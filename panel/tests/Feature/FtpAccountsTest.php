<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\FtpAccount;
use App\Models\HostingPackage;
use App\Models\User;
use App\Services\Agent\AgentException;
use App\Services\FtpAccounts;
use App\Support\TeamAreas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FtpAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $package = []): array
    {
        $plan = HostingPackage::create(array_merge(['name' => 'Basic', 'disk_mb' => 5000, 'max_websites' => 1], $package));
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@klant.nl']);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $site = $this->withPackage($customer->websites()->create([
            'domain' => 'klant.nl', 'status' => 'active', 'php_version' => '8.4', 'document_root' => '/var/www/vhosts/klant.nl/public',
        ]), $plan);

        return [$customer, $user, $site];
    }

    private function lastSync(): array
    {
        $call = collect($this->agent()->calls)->where('action', 'ftp.sync')->last();
        $this->assertNotNull($call, 'ftp.sync was not called');

        return $call['params']['accounts'];
    }

    public function test_client_adds_an_account_with_a_generated_password(): void
    {
        [, $user, $site] = $this->customer();
        $this->actingAs($user)->get("/websites/{$site->id}?tab=hosting")->assertOk()
            ->assertSee(__('FTP accounts'))->assertSee(__('Add FTP account'))->assertSee('2222');

        $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", [
            'name' => 'Designer', 'folder' => '/public/wp-content/themes/', 'read_only' => '0', 'description' => 'Web designer',
        ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('ftp_password');

        $account = FtpAccount::sole();
        $this->assertSame(['designer@klant.nl', 'public/wp-content/themes', false], [$account->login, $account->folder, $account->read_only]);
        $password = session('ftp_password')['password'];
        $this->assertSame($account->password_hash, crypt($password, $account->password_hash), 'the hash belongs to the shown password');
        $this->assertMatchesRegularExpression('/^\$6\$rounds=100000\$[A-Za-z0-9]{16}\$[.\/A-Za-z0-9]{86}$/', $account->password_hash);

        $this->assertSame([[
            'login' => 'designer@klant.nl', 'domain' => 'klant.nl', 'password_hash' => $account->password_hash,
            'folder' => 'public/wp-content/themes', 'read_only' => false,
        ]], $this->lastSync());
        $this->assertDatabaseHas('activity_logs', ['action' => 'ftp_account.created', 'subject' => 'designer@klant.nl']);
        $this->assertStringNotContainsString($password, json_encode(ActivityLog::all()->toArray()));

        $this->actingAs($user)->get('/ftp-accounts')->assertOk()->assertSee('designer@klant.nl')->assertSee('/public/wp-content/themes')
            ->assertDontSee($account->password_hash);
    }

    public function test_own_password_read_only_and_the_default_folder(): void
    {
        [, $user, $site] = $this->customer();
        $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", [
            'name' => 'foto', 'password' => 'een-lang-wachtwoord-1', 'read_only' => '1',
        ])->assertSessionHasNoErrors()->assertSessionMissing('ftp_password');

        $account = FtpAccount::sole();
        $this->assertSame(['public', true], [$account->folder, $account->read_only]);
        $this->assertTrue(hash_equals($account->password_hash, crypt('een-lang-wachtwoord-1', $account->password_hash)));
        $this->assertTrue($this->lastSync()[0]['read_only']);
    }

    public function test_bad_names_folders_and_passwords_are_refused(): void
    {
        [, $user, $site] = $this->customer();
        $cases = [
            ['name' => 'jan smit'], ['name' => '-jan'], ['name' => 'jan@x'], ['name' => str_repeat('a', 33)],
            ['name' => 'jan', 'folder' => '../andere-site'], ['name' => 'jan', 'folder' => 'public/../../etc'],
            ['name' => 'jan', 'folder' => 'public/a:b'], ['name' => 'jan', 'password' => 'kort'],
        ];
        foreach ($cases as $form) {
            $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", $form)->assertSessionHasErrors();
        }
        $this->assertSame(0, FtpAccount::count());

        $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", ['name' => 'jan'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", ['name' => 'JAN'])->assertSessionHasErrors('name');
        $this->assertSame(1, FtpAccount::count());
        $this->assertSame('', FtpAccounts::normaliseFolder('/'));
        $this->assertSame('public/uploads', FtpAccounts::normaliseFolder('\\public\\uploads\\'));
    }

    public function test_package_limit_applies_to_customers_but_not_to_admins(): void
    {
        [, $user, $site] = $this->customer();
        $site->package->forceFill(['crm_limits' => json_encode(['ftp_accounts' => 1])])->save();

        $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", ['name' => 'een'])->assertSessionHasNoErrors();
        $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", ['name' => 'twee'])->assertSessionHasErrors('name');
        $this->actingAs($user)->get("/websites/{$site->id}?tab=hosting")->assertSee('1 of 1 used', false);

        $this->actingAsAdmin();
        $this->post("/admin/ftp-accounts/websites/{$site->id}", ['name' => 'twee'])->assertSessionHasNoErrors();
        $this->assertSame(2, FtpAccount::count());
    }

    public function test_customers_cannot_touch_other_websites(): void
    {
        [, $user] = $this->customer();
        $other = Customer::create(['name' => 'Piet', 'email' => 'piet@x.nl']);
        $site = $other->websites()->create(['domain' => 'piet.nl', 'status' => 'active']);
        $account = app(FtpAccounts::class)->create($site, ['name' => 'piet', 'password' => 'een-lang-wachtwoord-1'], false);

        $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", ['name' => 'x'])->assertNotFound();
        $this->actingAs($user)->put("/ftp-accounts/{$account->id}", ['read_only' => '1'])->assertNotFound();
        $this->actingAs($user)->put("/ftp-accounts/{$account->id}/password", [])->assertNotFound();
        $this->actingAs($user)->delete("/ftp-accounts/{$account->id}")->assertNotFound();
        $this->actingAs($user)->get('/ftp-accounts')->assertOk()->assertDontSee('piet@piet.nl');
        $this->assertFalse($account->fresh()->read_only);
    }

    public function test_edit_new_password_switch_off_and_remove(): void
    {
        [, $user, $site] = $this->customer();
        $account = app(FtpAccounts::class)->create($site, ['name' => 'jan', 'password' => 'een-lang-wachtwoord-1']);
        $old = $account->password_hash;

        $this->actingAs($user)->put("/ftp-accounts/{$account->id}", ['folder' => 'public/uploads', 'read_only' => '1', 'enabled' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(['public/uploads', true], [$account->fresh()->folder, $account->fresh()->read_only]);
        $this->actingAs($user)->put("/ftp-accounts/{$account->id}", ['folder' => '../x'])->assertSessionHasErrors('folder');

        $this->actingAs($user)->put("/ftp-accounts/{$account->id}/password", [])->assertSessionHas('ftp_password');
        $this->assertNotSame($old, $account->fresh()->password_hash);
        $this->assertSame($account->fresh()->password_hash, $this->lastSync()[0]['password_hash']);

        $this->actingAs($user)->put("/ftp-accounts/{$account->id}", ['enabled' => '0']);
        $this->assertSame([], $this->lastSync(), 'a switched off account is not sent to the server');

        $this->actingAs($user)->delete("/ftp-accounts/{$account->id}")->assertRedirect();
        $this->assertSame(0, FtpAccount::count());
        $this->assertDatabaseHas('activity_logs', ['action' => 'ftp_account.deleted', 'subject' => 'jan@klant.nl']);
    }

    public function test_suspending_or_removing_the_website_closes_its_accounts(): void
    {
        [, , $site] = $this->customer();
        app(FtpAccounts::class)->create($site, ['name' => 'jan', 'password' => 'een-lang-wachtwoord-1']);
        $this->assertCount(1, $this->lastSync());

        $site->update(['status' => 'suspended']);
        $this->assertSame([], $this->lastSync());
        $site->update(['status' => 'active']);
        $this->assertCount(1, $this->lastSync());

        $site->delete();
        $this->assertSame(0, FtpAccount::count());
        $this->assertSame([], $this->lastSync());
    }

    public function test_an_unreachable_server_is_retried_by_the_scheduler(): void
    {
        [, $user, $site] = $this->customer();
        $this->agent()->respond('ftp.sync', new AgentException('unreachable', 'agent down'));
        $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", ['name' => 'jan'])->assertSessionHas('warnings');
        $this->assertSame(1, FtpAccount::count());

        $this->agent()->respond('ftp.sync', fn (array $p) => ['accounts' => count($p['accounts']), 'skipped' => []]);
        $this->artisan('whp:ftp:sync --pending')->assertSuccessful();
        $this->assertCount(1, $this->lastSync());
        $calls = collect($this->agent()->calls)->where('action', 'ftp.sync')->count();
        $this->artisan('whp:ftp:sync --pending')->assertSuccessful();
        $this->assertSame($calls, collect($this->agent()->calls)->where('action', 'ftp.sync')->count(), 'nothing pending any more');
    }

    public function test_customer_switch_off_and_team_members_need_the_files_area(): void
    {
        [$customer, $user, $site] = $this->customer();
        app(FtpAccounts::class)->create($site, ['name' => 'jan', 'password' => 'een-lang-wachtwoord-1']);

        config(['ftp-accounts.customer_manage' => false]);
        $this->actingAs($user)->post("/ftp-accounts/websites/{$site->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($user)->get("/websites/{$site->id}?tab=hosting")->assertOk()->assertSee('jan@klant.nl')->assertDontSee(__('Add FTP account'));
        config(['ftp-accounts.customer_manage' => true]);

        $this->assertSame('files', TeamAreas::areaFor('client.ftp-accounts.index'));
    }

    public function test_admin_screens(): void
    {
        [, , $site] = $this->customer();
        app(FtpAccounts::class)->create($site, ['name' => 'jan', 'password' => 'een-lang-wachtwoord-1', 'read_only' => true]);
        $this->actingAsAdmin();

        $this->get('/admin/ftp-accounts')->assertOk()->assertSee('jan@klant.nl')->assertSee(__('Read only'))->assertSee('SHA256:');
        $this->get("/admin/websites/{$site->id}?tab=hosting")->assertOk()->assertSee('jan@klant.nl');
        $this->get("/admin/websites/{$site->id}")->assertOk()->assertSee(__('FTP accounts'));

        $this->actingAsAdmin(User::factory()->admin()->create(['locale' => 'nl']));
        $this->get('/admin/ftp-accounts')->assertOk()->assertSee('FTP-accounts')->assertSee('Alleen lezen');
    }
}
