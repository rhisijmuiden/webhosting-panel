<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SiteDatabase;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Rhis\DiskUsage\DiskUsage;
use Rhis\DnsMail\Models\Mailbox;
use Rhis\DnsMail\Models\MailDomain;
use Tests\TestCase;

class DiskUsageTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $user;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create(['name' => 'Jan', 'email' => 'jan@klant.nl']);
        $this->user = User::factory()->create(['customer_id' => $this->customer->id]);
        $this->site = $this->customer->websites()->create(['domain' => 'klant.nl', 'status' => 'active', 'php_version' => '8.4', 'disk_used_mb' => 900, 'disk_quota_mb' => 3000]);
        SiteDatabase::create(['website_id' => $this->site->id, 'name' => 'klant_wp', 'users' => ['klant_wp']]);
        $domain = MailDomain::firstOrCreate(['domain' => 'klant.nl'], ['customer_id' => $this->customer->id, 'dns_managed' => false]);
        $domain->update(['website_id' => $this->site->id]);
        Mailbox::create(['mail_domain_id' => $domain->id, 'local_part' => 'info', 'password_hash' => 'x', 'quota_mb' => 1024, 'used_kb' => 512 * 1024]);
    }

    public function test_the_client_drills_down_into_folders(): void
    {
        // A single website: the overview goes straight to its explorer, which starts the first scan.
        $this->actingAs($this->user)->get('/disk-usage')->assertRedirect("/disk-usage/{$this->site->id}");
        $this->actingAs($this->user)->get("/disk-usage/{$this->site->id}")->assertOk()
            ->assertSee(__('The first scan is running. This takes from a few seconds to a few minutes.'));
        $this->assertSame('klant.nl', collect($this->agent()->calls)->where('action', 'disk.scan')->last()['params']['domain']);

        $this->actingAs($this->user)->get("/disk-usage/{$this->site->id}")->assertOk()
            ->assertSee('public')->assertSee('logs')
            ->assertSee(__('Largest files'))->assertSee('backup_2026-10-01-db.zip')->assertSee(__('Archive or export: an old backup?'))
            ->assertSee('klant_wp')->assertSee('info@klant.nl')
            ->assertSee(__('Where the space goes'))->assertSee(__('Databases and mailboxes have their own limits.'));

        $this->actingAs($this->user)->get("/disk-usage/{$this->site->id}?path=public/wp-content")->assertOk()
            ->assertSee('uploads')->assertSee('updraft')
            ->assertSee(__('Cache: safe to empty, it is rebuilt automatically.'))
            ->assertSee(__('Backups stored inside the website count towards your disk space. The panel already makes backups for you.'));
        $this->assertSame('public/wp-content', collect($this->agent()->calls)->where('action', 'disk.usage')->last()['params']['path']);

        // The path is cleaned before it reaches the agent.
        $this->actingAs($this->user)->get("/disk-usage/{$this->site->id}?path=../../etc/./passwd")->assertOk();
        $this->assertSame('etc/passwd', collect($this->agent()->calls)->where('action', 'disk.usage')->last()['params']['path']);

        $this->actingAs($this->user)->post("/disk-usage/{$this->site->id}/scan", ['path' => 'public'])
            ->assertRedirect("/disk-usage/{$this->site->id}?path=public")->assertSessionHas('status');
    }

    public function test_dutch_translation(): void
    {
        $this->user->update(['locale' => 'nl']);
        Cache::put('fake-disk.scanned.klant.nl', time() - 60, 3600);
        app()->setLocale('nl');
        $this->actingAs($this->user)->withSession(['locale' => 'nl'])->get("/disk-usage/{$this->site->id}")->assertOk()
            ->assertSee('Waar de ruimte heen gaat')->assertSee('Mappen');
    }

    public function test_customers_never_see_other_websites(): void
    {
        $other = Customer::create(['name' => 'Piet', 'email' => 'piet@ander.nl'])->websites()->create(['domain' => 'ander.nl', 'status' => 'active', 'php_version' => '8.4']);
        $this->actingAs($this->user)->get("/disk-usage/{$other->id}")->assertNotFound();
        $this->actingAs($this->user)->post("/disk-usage/{$other->id}/scan")->assertNotFound();
        $this->assertEmpty(collect($this->agent()->calls)->where('action', 'disk.scan')->all());
    }

    public function test_team_members_need_the_files_area_and_only_see_names_of_their_areas(): void
    {
        $piet = User::factory()->create(['customer_id' => $this->customer->id]);
        $member = TeamMember::create(['user_id' => $piet->id, 'customer_id' => $this->customer->id, 'all_websites' => true, 'areas' => ['files']]);
        Cache::put('fake-disk.scanned.klant.nl', time() - 60, 3600);

        $this->actingAs($piet)->get("/disk-usage/{$this->site->id}")->assertOk()
            ->assertSee('public')->assertDontSee('klant_wp')->assertDontSee('info@klant.nl');

        $member->update(['areas' => ['mail']]);
        $this->actingAs($piet)->get("/disk-usage/{$this->site->id}")->assertForbidden();
    }

    public function test_the_admin_overview_folds_subdomains_into_their_parent_and_flags_full_sites(): void
    {
        $sub = $this->customer->websites()->create(['domain' => 'test.klant.nl', 'status' => 'active', 'php_version' => '8.4', 'disk_used_mb' => 2000]);
        $sub->parent_id = $this->site->id;
        $sub->save();
        Customer::create(['name' => 'Piet', 'email' => 'piet@ander.nl'])->websites()->create(['domain' => 'ander.nl', 'status' => 'active', 'php_version' => '8.4', 'disk_used_mb' => 10]);

        $this->actingAsAdmin();
        $this->get('/admin/disk-usage')->assertOk()
            ->assertSee('klant.nl')->assertSee('ander.nl')->assertSee(__('Nearly full'))
            ->assertSeeInOrder(['klant.nl', 'ander.nl'])
            ->assertDontSee('>test.klant.nl<', false);

        $rows = app(DiskUsage::class)->overview(Website::with('subdomains')->get());
        $klant = $rows->firstWhere('website.domain', 'klant.nl');
        $this->assertSame(2900 * 1024 * 1024, $klant['files'], 'own files plus the subdomain');
        $this->assertSame(512 * 1024 * 1024, $klant['mail']);
        $this->assertGreaterThan(0, $klant['databases']);
        $this->assertTrue($klant['near_full']);
        $this->assertCount(2, $rows);

        $this->get('/admin/disk-usage?full=1')->assertOk()->assertSee('klant.nl')->assertDontSee('ander.nl');
        $this->get('/admin/disk-usage?q=piet')->assertOk()->assertSee('ander.nl')->assertDontSee('>klant.nl<', false);
        $this->get("/admin/disk-usage/{$sub->id}")->assertOk()
            ->assertSee(__('This is a subdomain of :domain. Its files count towards the disk space of :domain.', ['domain' => 'klant.nl']));
        $this->get("/admin/disk-usage/{$this->site->id}")->assertOk()->assertSee('test.klant.nl');
    }

    public function test_an_unreachable_agent_is_shown_not_thrown(): void
    {
        $this->agent()->respond('disk.summary', new AgentException('unreachable', 'Agent socket not found'));
        $this->agent()->respond('disk.usage', new AgentException('unreachable', 'Agent socket not found'));
        $this->actingAsAdmin();
        $this->get('/admin/disk-usage')->assertOk()->assertSee(__('The server agent is not reachable.'))->assertSee('klant.nl');
        $this->get("/admin/disk-usage/{$this->site->id}")->assertOk()->assertSee('Agent socket not found');
    }

    public function test_the_nightly_scan_asks_the_agent(): void
    {
        $this->artisan('disk-usage:scan')->assertSuccessful();
        $this->assertSame(['max_age_hours' => 20], collect($this->agent()->calls)->where('action', 'disk.scan_all')->last()['params']);
        $this->artisan('disk-usage:scan --all')->assertSuccessful();
        $this->assertSame(['max_age_hours' => 0], collect($this->agent()->calls)->where('action', 'disk.scan_all')->last()['params']);
    }

    public function test_menu_items(): void
    {
        $this->actingAs($this->user)->get('/dashboard')->assertOk()->assertSee(route('client.disk-usage.index'));
        $this->actingAsAdmin();
        $this->get('/admin')->assertSee(route('admin.disk-usage.index'));
    }
}
