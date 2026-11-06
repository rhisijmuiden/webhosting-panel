<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Rhis\Backups\Models\ClientBackupTarget;
use Rhis\Backups\OneDrive;
use Tests\TestCase;

class ClientOneDriveBackupTest extends TestCase
{
    use RefreshDatabase;

    private const APP = '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0';

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $this->user = User::factory()->create(['customer_id' => $this->customer->id]);
        Website::create(['customer_id' => $this->customer->id, 'domain' => 'klant.nl', 'status' => 'active']);
    }

    private function configure(): void
    {
        app(Settings::class)->set('backups.onedrive.client_id', self::APP);
        app(Settings::class)->set('backups.onedrive.client_secret', 'app-secret', encrypted: true);
    }

    private function fakeMicrosoft(): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'AT1', 'refresh_token' => 'RT1', 'token_type' => 'Bearer', 'expires_in' => 3600]),
            'graph.microsoft.com/v1.0/me/drive*' => Http::response(['id' => 'b!drive123', 'driveType' => 'business']),
            'graph.microsoft.com/v1.0/me*' => Http::response(['mail' => 'jan@klant.nl', 'displayName' => 'Jan']),
        ]);
    }

    /** Walk through "Connect with Microsoft" and come back with a code. */
    private function connect(array $form = []): void
    {
        $res = $this->actingAs($this->user)->post('/backups/onedrive/connect', $form + ['name' => 'Mijn OneDrive', 'schedule' => 'daily']);
        $url = $res->headers->get('Location');
        $this->assertStringStartsWith(OneDrive::LOGIN.'/authorize?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(self::APP, $q['client_id']);
        $this->assertSame(route('client.backups.onedrive.callback'), $q['redirect_uri']);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->actingAs($this->user)->get('/backups/onedrive/callback?code=CODE&state='.$q['state'])
            ->assertRedirect(route('client.backups.locations.create'))->assertSessionHas('status');
    }

    public function test_onedrive_is_offered_only_when_the_admin_registered_the_microsoft_app(): void
    {
        $this->actingAs($this->user)->get('/backups/locations/create')->assertOk()
            ->assertSee('Microsoft OneDrive')->assertSee('the administrator has not connected the panel to Microsoft');
        $this->actingAs($this->user)->post('/backups/onedrive/connect')->assertNotFound();

        $this->configure();
        $this->actingAs($this->user)->get('/backups/locations/create')->assertOk()->assertSee('Connect with Microsoft');
    }

    public function test_connecting_and_saving_a_onedrive_location(): void
    {
        $this->configure();
        $this->fakeMicrosoft();
        $this->connect();
        Http::assertSent(fn ($r) => str_contains($r->url(), '/token') && $r['code'] === 'CODE' && $r['client_secret'] === 'app-secret' && strlen($r['code_verifier']) === 64);

        // The form comes back filled in, with the account shown.
        $this->actingAs($this->user)->get('/backups/locations/create')->assertSee('jan@klant.nl')->assertSee('Mijn OneDrive');

        $this->agent()->respond('client_backup.target_test', ['reachable' => true, 'writable' => true, 'host_key' => null, 'fingerprints' => [],
            'token' => ['access_token' => 'AT2', 'token_type' => 'Bearer', 'refresh_token' => 'RT2', 'expiry' => '2026-11-06T20:00:00Z']]);
        $res = $this->actingAs($this->user)->post('/backups/locations', [
            'name' => 'Mijn OneDrive', 'type' => 'onedrive', 'path' => 'Backups', 'schedule' => 'daily', 'schedule_time' => '03:00',
            'full_day' => 'Sun', 'keep_full' => 4, 'parts' => ['files', 'databases', 'mail'],
        ]);
        $target = ClientBackupTarget::firstOrFail();
        $res->assertRedirect(route('client.backups.locations.show', $target->id));
        $this->assertSame(['onedrive', 'b!drive123', 'business', 'jan@klant.nl', null], [$target->type, $target->drive_id, $target->drive_type, $target->account, $target->host]);
        $this->assertSame('RT2', json_decode($target->onedrive_token, true)['refresh_token'], 'the renewed token from the test is kept');
        $this->assertStringNotContainsString('refresh_token', (string) \DB::table('client_backup_targets')->value('onedrive_token'));

        $call = collect($this->agent()->calls)->firstWhere('action', 'client_backup.target_test');
        $sent = $call['params']['target'];
        $this->assertSame(['onedrive', 'b!drive123', 'business', 'Backups', self::APP, 'app-secret'],
            [$sent['type'], $sent['drive_id'], $sent['drive_type'], $sent['path'], $sent['client_id'], $sent['client_secret']]);
        $this->assertSame('RT1', json_decode($sent['token'], true)['refresh_token']);
        $this->assertSame($target->id, $target->agentTarget()['id']);

        // The connection is used once; editing keeps the stored token.
        $this->actingAs($this->user)->get("/backups/locations/{$target->id}/edit")->assertOk()->assertSee('jan@klant.nl');
        $this->actingAs($this->user)->put("/backups/locations/{$target->id}", [
            'name' => 'OneDrive werk', 'type' => 'onedrive', 'path' => 'Backups', 'schedule' => 'off', 'schedule_time' => '03:00',
            'full_day' => 'Sun', 'keep_full' => 4, 'parts' => ['files'],
        ])->assertRedirect(route('client.backups.locations.show', $target->id));
        $this->assertSame('OneDrive werk', $target->fresh()->name);
        $this->assertSame('b!drive123', $target->fresh()->drive_id);
        $this->actingAs($this->user)->get("/backups/locations/{$target->id}")->assertOk()->assertSee('OneDrive (jan@klant.nl)');
    }

    public function test_saving_onedrive_without_a_connection_is_refused(): void
    {
        $this->configure();
        $this->actingAs($this->user)->post('/backups/locations', [
            'name' => 'OD', 'type' => 'onedrive', 'schedule' => 'off', 'schedule_time' => '03:00', 'full_day' => 'Sun', 'keep_full' => 4,
            'parts' => ['files'],
        ])->assertSessionHasErrors('onedrive');
        $this->assertSame(0, ClientBackupTarget::count());
    }

    public function test_callback_checks_state_and_customer(): void
    {
        $this->configure();
        $this->fakeMicrosoft();
        $this->actingAs($this->user)->post('/backups/onedrive/connect', ['name' => 'X']);
        $this->actingAs($this->user)->get('/backups/onedrive/callback?code=CODE&state=wrong')->assertSessionHasErrors('agent');
        $this->assertNull(session('backups.onedrive.connection'));
        Http::assertNothingSent();

        // Without a pending connection (or for someone else) there is nothing to finish.
        $this->actingAs($this->user)->get('/backups/onedrive/callback?code=CODE&state=x')->assertNotFound();
        $other = User::factory()->create(['customer_id' => Customer::create(['name' => 'Piet', 'email' => 'p@example.test'])->id]);
        $this->actingAs($this->user)->post('/backups/onedrive/connect', ['name' => 'X']);
        $this->actingAs($other)->get('/backups/onedrive/callback?code=CODE&state=x')->assertNotFound();

        // Cancelled at Microsoft.
        $res = $this->actingAs($this->user)->post('/backups/onedrive/connect', ['name' => 'X']);
        parse_str(parse_url($res->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->actingAs($this->user)->get('/backups/onedrive/callback?error=access_denied&state='.$q['state'])->assertSessionHasErrors('agent');
    }

    public function test_admin_registers_the_microsoft_app(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/backups/customers')->assertOk()->assertSee('OneDrive for customers')->assertSee(route('client.backups.onedrive.callback'));
        $this->put('/admin/backups/onedrive', ['client_id' => 'nope', 'client_secret' => 's'])->assertSessionHasErrors('client_id');
        $this->put('/admin/backups/onedrive', ['client_id' => strtoupper(self::APP), 'client_secret' => 'app-secret'])->assertSessionHasNoErrors();
        $onedrive = app(OneDrive::class);
        $this->assertTrue($onedrive->configured());
        $this->assertSame(['client_id' => self::APP, 'client_secret' => 'app-secret'], $onedrive->agentCredentials());
        $this->assertStringNotContainsString('app-secret', (string) \DB::table('settings')->where('key', 'backups.onedrive.client_secret')->value('value'));
        // An empty secret keeps the current one.
        $this->put('/admin/backups/onedrive', ['client_id' => self::APP])->assertSessionHasNoErrors();
        $this->assertTrue($onedrive->configured());
        $this->put('/admin/backups/onedrive', ['remove' => 1]);
        $this->assertFalse($onedrive->configured());

        $this->actingAs($this->user)->put('/admin/backups/onedrive', ['remove' => 1])->assertForbidden();
    }
}
