<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Rhis\DnsMail\Models\MailDomain;
use Tests\TestCase;

class SubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    private function calls(string $action)
    {
        return collect($this->agent()->calls)->where('action', $action)->values();
    }

    /** @return array{0: Customer, 1: HostingPackage, 2: Website} */
    private function subscription(string $domain = 'arie-oud.nl', ?HostingPackage $package = null, ?Customer $customer = null): array
    {
        $package ??= HostingPackage::firstOrCreate(['name' => 'MKB - Basis S'], ['disk_mb' => 5000]);
        $customer ??= Customer::create(['name' => 'Arie', 'email' => 'arie@example.test']);
        $site = $this->withPackage(Website::create(['customer_id' => $customer->id, 'domain' => $domain, 'status' => 'active']), $package);

        return [$customer, $package, $site];
    }

    public function test_every_website_gets_a_subscription(): void
    {
        $customer = Customer::create(['name' => 'Jan', 'email' => 'jan@example.test']);
        $site = Website::create(['customer_id' => $customer->id, 'domain' => 'jan.nl']);
        $this->assertSame('active', $site->subscription->status);
        $this->assertNull($site->package);
        $this->assertSame([$site->subscription->id], $customer->subscriptions()->pluck('subscriptions.id')->all());
        $this->assertSame('s'.$site->subscription->id, $site->subscription->sliceOwner());

        $site->delete();
        $this->assertSame(0, Subscription::count());
    }

    public function test_list_with_search_sorting_paging_and_dutch(): void
    {
        $basic = HostingPackage::create(['name' => 'MKB - Basis S']);
        $total = HostingPackage::create(['name' => 'MKB - Totaal']);
        [$arie] = $this->subscription('arie-oud.nl', $basic);
        $hakkuh = Customer::create(['name' => 'Hakkuh', 'company' => 'Hakkuhbal', 'email' => 'h@example.test']);
        [, , $hk] = $this->subscription('hakkuhbal.nl', $total, $hakkuh);
        $hk->subscription->update(['expires_on' => '2020-01-01']);
        $this->actingAsAdmin();

        $this->get('/admin/subscriptions')->assertOk()
            ->assertSeeInOrder(['arie-oud.nl', '(MKB - Basis S)', 'hakkuhbal.nl', '(MKB - Totaal)'])
            ->assertSee('2 items in total')->assertSee('Hakkuhbal')->assertSee('subs-expired', false);
        $this->get('/admin/subscriptions?sort=domain&dir=desc')->assertSeeInOrder(['hakkuhbal.nl', 'arie-oud.nl']);
        $this->get('/admin/subscriptions?q=totaal')->assertSee('hakkuhbal.nl')->assertDontSee('arie-oud.nl');
        $this->get('/admin/subscriptions?q=hakkuh')->assertSee('hakkuhbal.nl')->assertDontSee('arie-oud.nl');
        $this->get("/admin/subscriptions?customer={$arie->id}")->assertSee('arie-oud.nl')->assertDontSee('hakkuhbal.nl');
        $this->get('/admin/subscriptions?per=10&sort=expires')->assertOk();
        $this->get('/admin/subscriptions?per=all&sort=owner&dir=desc')->assertOk()->assertSeeInOrder(['hakkuhbal.nl', 'arie-oud.nl']);
        $this->get("/admin/subscriptions/{$hk->subscription->id}/edit")->assertOk()->assertSee('whp-sites-s'.$hk->subscription->id.'.slice');

        $this->get('/admin')->assertSee(route('admin.subscriptions.index'), false);
        auth()->user()->update(['locale' => 'nl']);
        $this->get('/admin/subscriptions')->assertSee('Abonnementen')->assertSee('Pakket wijzigen')
            ->assertSee('Abonnementhouder wijzigen')->assertSee('Datum van instellen')->assertSee('Verloopdatum');
    }

    public function test_bulk_change_package(): void
    {
        $big = HostingPackage::create(['name' => 'Groot', 'disk_mb' => 20000, 'memory_mb' => 2048]);
        [, , $a] = $this->subscription('a.nl');
        [, , $b] = $this->subscription('b.nl', null, $a->customer);
        $this->actingAsAdmin();

        $this->post('/admin/subscriptions/bulk', ['action' => 'package'])->assertSessionHasErrors('ids');
        $this->post('/admin/subscriptions/bulk', ['action' => 'package', 'ids' => [$a->subscription->id, $b->subscription->id], 'hosting_package_id' => $big->id])
            ->assertSessionHas('status', '2 subscriptions changed.');
        $this->assertSame([$big->id, $big->id], [$a->subscription->fresh()->hosting_package_id, $b->subscription->fresh()->hosting_package_id]);
        $this->assertSame(20000, $a->fresh()->disk_quota_mb);
        $this->assertSame(['a.nl', 'b.nl'], $this->calls('site.quota')->pluck('params.domain')->sort()->values()->all());
        $this->assertSame(2048, $this->calls('subscription.limits')->last()['params']['memory_mb']);

        // No package: everything unlimited (quota 0 = none).
        $this->post('/admin/subscriptions/bulk', ['action' => 'package', 'ids' => [$a->subscription->id], 'hosting_package_id' => ''])->assertSessionHasNoErrors();
        $this->assertNull($a->subscription->fresh()->hosting_package_id);
        $this->assertSame(0, $this->calls('site.quota')->last()['params']['quota_mb']);
    }

    public function test_bulk_change_owner_moves_the_website_and_its_mail_domain(): void
    {
        [$old, , $site] = $this->subscription('klant.nl');
        $new = Customer::create(['name' => 'Nieuw', 'email' => 'nieuw@example.test']);
        $mail = MailDomain::where('domain', 'klant.nl')->first() ?? MailDomain::create(['domain' => 'klant.nl', 'customer_id' => $old->id, 'website_id' => $site->id]);
        $this->actingAsAdmin();

        $this->post('/admin/subscriptions/bulk', ['action' => 'owner', 'ids' => [$site->subscription->id], 'customer_id' => $new->id])->assertSessionHasNoErrors();
        $this->assertSame($new->id, $site->fresh()->customer_id);
        $this->assertSame($new->id, $mail->fresh()->customer_id);
        $this->assertSame(0, $old->websites()->count());

        // The new owner's client login sees the website, the old one does not.
        $this->actingAs(User::factory()->create(['customer_id' => $new->id]))->get("/websites/{$site->id}")->assertOk();
        $this->actingAs(User::factory()->create(['customer_id' => $old->id]))->get("/websites/{$site->id}")->assertNotFound();
    }

    public function test_bulk_status_and_expiry(): void
    {
        [, , $site] = $this->subscription('klant.nl');
        $sub = $site->subscription;
        $this->actingAsAdmin();

        $this->post('/admin/subscriptions/bulk', ['action' => 'suspend', 'ids' => [$sub->id]])->assertSessionHasNoErrors();
        $this->assertSame(['suspended', Subscription::BY_ADMIN], [$sub->fresh()->status, $sub->fresh()->suspended_reason]);
        $this->assertSame('suspended', $site->fresh()->status);
        $this->assertSame(['domain' => 'klant.nl'], $this->calls('site.suspend')->last()['params']);
        $this->get('/admin/subscriptions')->assertSee('Suspended by the administrator');

        $this->post('/admin/subscriptions/bulk', ['action' => 'activate', 'ids' => [$sub->id]])->assertSessionHasNoErrors();
        $this->assertSame(['active', 'active'], [$sub->fresh()->status, $site->fresh()->status]);
        $this->assertCount(1, $this->calls('site.unsuspend'));

        $this->post('/admin/subscriptions/bulk', ['action' => 'expiry', 'ids' => [$sub->id], 'expires_on' => '2030-06-01'])->assertSessionHasNoErrors();
        $this->assertSame('2030-06-01', $sub->fresh()->expires_on->toDateString());
    }

    public function test_expired_subscriptions_are_suspended(): void
    {
        [, , $expired] = $this->subscription('oud.nl');
        [, , $current] = $this->subscription('nieuw.nl', null, $expired->customer);
        $expired->subscription->update(['expires_on' => now()->subDay()->toDateString()]);
        $current->subscription->update(['expires_on' => now()->toDateString()]);

        $this->artisan('whp:subscriptions:expire')->assertSuccessful();
        $this->assertSame(['suspended', Subscription::EXPIRED], [$expired->subscription->fresh()->status, $expired->subscription->fresh()->suspended_reason]);
        $this->assertSame('suspended', $expired->fresh()->status);
        $this->assertSame('active', $current->subscription->fresh()->status, 'the expiry day itself still counts');
    }

    public function test_bulk_remove_needs_confirmation(): void
    {
        [, , $site] = $this->subscription('weg.nl');
        $this->actingAsAdmin();
        $this->post('/admin/subscriptions/bulk', ['action' => 'remove', 'ids' => [$site->subscription->id]])->assertSessionHasErrors('confirm');
        $this->assertSame('active', $site->fresh()->status);
        $this->post('/admin/subscriptions/bulk', ['action' => 'remove', 'ids' => [$site->subscription->id], 'confirm' => '1'])
            ->assertSessionHas('status', '1 subscription is being removed.');
        $this->assertSame(['domain' => 'weg.nl'], collect($this->calls('site.remove')->last()['params'])->only('domain')->all());
    }

    public function test_edit_page_saves_everything(): void
    {
        [, , $site] = $this->subscription('klant.nl');
        $other = Customer::create(['name' => 'Ander', 'email' => 'ander@example.test']);
        $pro = HostingPackage::create(['name' => 'Pro']);
        $this->actingAsAdmin();
        $this->put("/admin/subscriptions/{$site->subscription->id}", [
            'hosting_package_id' => $pro->id, 'customer_id' => $other->id, 'status' => 'suspended', 'expires_on' => '2031-01-31', 'notes' => 'Factuur via CRM',
        ])->assertRedirect(route('admin.subscriptions.index'));
        $sub = $site->subscription->fresh();
        $this->assertSame([$pro->id, 'suspended', '2031-01-31', 'Factuur via CRM'], [$sub->hosting_package_id, $sub->status, $sub->expires_on->toDateString(), $sub->notes]);
        $this->assertSame($other->id, $site->fresh()->customer_id);
    }

    public function test_client_sees_the_subscription_of_each_website(): void
    {
        [$customer, , $site] = $this->subscription('klant.nl');
        $site->subscription->update(['expires_on' => '2031-01-31']);
        $user = User::factory()->create(['customer_id' => $customer->id]);
        $this->actingAs($user)->get("/websites/{$site->id}")->assertOk()->assertSee('MKB - Basis S')->assertSee('Expiry date');
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('Subscriptions')->assertSee('MKB - Basis S');
    }

    public function test_packages_in_use_by_a_subscription_cannot_be_deleted(): void
    {
        [, $package] = $this->subscription();
        $this->actingAsAdmin();
        $this->delete("/admin/packages/{$package->id}")->assertSessionHasErrors('package');
        $this->get('/admin/packages')->assertOk();
        $this->assertSame(1, DB::table('hosting_packages')->count());
    }
}
