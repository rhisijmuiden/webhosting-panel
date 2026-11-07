<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteLogsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Website $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create(['name' => 'Klant', 'email' => 'info@klant.nl']);
        $this->site = Website::create(['customer_id' => $this->customer->id, 'domain' => 'klant.nl', 'status' => 'active', 'system_user' => 's_klant_nl']);
    }

    private function owner(): User
    {
        return User::factory()->create(['customer_id' => $this->customer->id]);
    }

    public function test_customer_with_one_website_goes_straight_to_its_logs(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->get('/logs')->assertRedirect("/logs/{$this->site->id}");
        $this->actingAs($user)->get("/logs/{$this->site->id}?log=php")->assertOk()
            ->assertSee('klant.nl')->assertSee('siteLogs', false)->assertSee('assets/site-logs/site-logs.js', false)
            ->assertSee('PHP-FPM');
        $this->actingAs($user)->get('/dashboard')->assertSee(route('client.site-logs.index'));
    }

    public function test_read_passes_filters_and_cursor_to_the_agent(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->getJson("/logs/{$this->site->id}/read?".http_build_query([
            'log' => 'access', 'lines' => 100, 'q' => "wp-login\n", 'status' => ['4xx', '5xx', '4xx'], 'ip' => '203.0.113.5',
            'method' => 'post', 'inode' => 12, 'offset' => 3400,
        ]))->assertOk()->assertJsonStructure(['entries', 'cursor' => ['inode', 'offset']]);

        $call = collect($this->agent()->calls)->firstWhere('action', 'sitelog.read');
        $this->assertSame('klant.nl', $call['params']['domain']);
        $this->assertSame('wp-login', $call['params']['q']);
        $this->assertSame(['4xx', '5xx'], $call['params']['status']);
        $this->assertSame(100, $call['params']['lines']);
        $this->assertSame(['inode' => 12, 'offset' => 3400], $call['params']['after']);
        $this->assertArrayNotHasKey('levels', $call['params']);
    }

    public function test_bad_parameters_are_refused_before_the_agent(): void
    {
        $user = $this->owner();
        foreach ([['log' => 'auth'], ['log' => 'access', 'file' => '../../etc/passwd'], ['log' => 'access', 'status' => ['6xx']],
            ['log' => 'php', 'levels' => ['fatal']], ['log' => 'access', 'lines' => 5000], ['log' => 'access', 'inode' => 1],
            ['log' => 'access', 'ip' => '1.2.3.4; rm']] as $query) {
            $this->actingAs($user)->getJson("/logs/{$this->site->id}/read?".http_build_query($query))->assertStatus(422);
        }
        $this->assertNull(collect($this->agent()->calls)->firstWhere('action', 'sitelog.read'));
    }

    public function test_customer_cannot_read_someone_elses_logs(): void
    {
        $other = Website::create(['customer_id' => Customer::create(['name' => 'Ander', 'email' => 'a@b.nl'])->id, 'domain' => 'ander.nl', 'status' => 'active']);
        $user = $this->owner();
        foreach (["/logs/{$other->id}", "/logs/{$other->id}/files", "/logs/{$other->id}/read?log=access"] as $url) {
            $this->actingAs($user)->getJson($url)->assertNotFound();
        }
        $this->assertSame([], preg_grep('/^sitelog\./', array_column($this->agent()->calls, 'action')));
    }

    public function test_team_member_needs_website_settings_and_the_website(): void
    {
        $blog = Website::create(['customer_id' => $this->customer->id, 'domain' => 'blog.nl', 'status' => 'active']);
        $member = function (array $areas) {
            $user = User::factory()->create(['customer_id' => $this->customer->id]);
            TeamMember::create(['user_id' => $user->id, 'customer_id' => $this->customer->id, 'all_websites' => false, 'areas' => $areas])
                ->websites()->sync([$this->site->id]);

            return $user;
        };

        $files = $member(['files']);
        $this->actingAs($files)->get("/logs/{$this->site->id}")->assertForbidden();

        $web = $member(['websites']);
        $this->actingAs($web)->get("/logs/{$this->site->id}")->assertOk();
        $this->actingAs($web)->getJson("/logs/{$this->site->id}/read?log=error")->assertOk();
        $this->actingAs($web)->get("/logs/{$blog->id}")->assertNotFound();
    }

    public function test_customer_access_can_be_switched_off(): void
    {
        config(['site-logs.customer_access' => false]);
        $user = $this->owner();
        $this->actingAs($user)->get('/logs')->assertNotFound();
        $this->actingAs($user)->get("/logs/{$this->site->id}")->assertNotFound();
        $this->actingAs($user)->get("/websites/{$this->site->id}")->assertOk()->assertDontSee(route('client.site-logs.show', $this->site));
    }

    public function test_agent_errors_become_readable_json(): void
    {
        $user = $this->owner();
        $this->agent()->respond('sitelog.read', new AgentException('forbidden', 'access.log is not a regular log file'));
        $this->actingAs($user)->getJson("/logs/{$this->site->id}/read?log=access")->assertStatus(403)
            ->assertJsonPath('code', 'forbidden')->assertJsonPath('message', 'This log file cannot be read safely: it is a link or not a normal file.');
        $this->agent()->respond('sitelog.files', AgentException::unreachable('down'));
        $this->actingAs($user)->getJson("/logs/{$this->site->id}/files")->assertStatus(503);
    }

    public function test_admin_sees_every_website_and_the_domain_page_links_to_the_logs(): void
    {
        $this->actingAsAdmin();
        $this->get('/admin/logs')->assertOk()->assertSee('klant.nl')->assertSee('Website logs');
        $this->get('/admin/logs?q=nothing')->assertOk()->assertDontSee('klant.nl');
        $this->get("/admin/logs/{$this->site->id}")->assertOk()->assertViewHas('config', fn ($c) => $c['readUrl'] === route('admin.site-logs.read', $this->site));
        $this->getJson("/admin/logs/{$this->site->id}/files")->assertOk()->assertJsonStructure(['logs' => ['access', 'error', 'php', 'fpm']]);
        $this->get("/admin/websites/{$this->site->id}?tab=tools")->assertOk()->assertSee(route('admin.site-logs.show', $this->site));
    }

    public function test_customer_domain_page_has_the_logs_tile_and_dutch_texts(): void
    {
        $user = $this->owner();
        $this->actingAs($user)->get("/websites/{$this->site->id}?tab=tools")->assertOk()
            ->assertSee(route('client.site-logs.show', $this->site))->assertSee('Logs and errors');
        $user->update(['locale' => 'nl']);
        $this->actingAs($user->fresh())->get("/logs/{$this->site->id}")->assertOk()->assertSee('Bezoekerslog')->assertSee('PHP-fouten');
    }
}
