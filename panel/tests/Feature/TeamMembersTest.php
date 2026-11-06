<?php

namespace Tests\Feature;

use App\Mail\TeamInvitationMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\TeamInvitation;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Website;
use App\Services\Team\TeamManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Rhis\DnsMail\Models\MailDomain;
use Tests\TestCase;

class TeamMembersTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private User $owner;

    private Website $shop;

    private Website $blog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::create(['name' => 'Jan', 'company' => 'Bakkerij Jan', 'email' => 'jan@example.test']);
        $this->owner = User::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Jan Bakker']);
        $this->shop = Website::create(['customer_id' => $this->customer->id, 'domain' => 'shop.nl', 'status' => 'active']);
        $this->blog = Website::create(['customer_id' => $this->customer->id, 'domain' => 'blog.nl', 'status' => 'active']);
    }

    /** A team member on shop.nl only, with the given areas. */
    private function member(array $areas = ['files'], bool $all = false): User
    {
        $user = User::factory()->create(['customer_id' => $this->customer->id, 'name' => 'Webbouwer Piet']);
        $member = TeamMember::create(['user_id' => $user->id, 'customer_id' => $this->customer->id, 'all_websites' => $all, 'areas' => $areas]);
        $member->websites()->sync($all ? [] : [$this->shop->id]);

        return $user;
    }

    public function test_owner_invites_and_the_invitee_joins_with_exactly_that_access(): void
    {
        Mail::fake();
        $this->actingAs($this->owner)->get('/team')->assertOk()->assertSee('Invite team member');
        $this->actingAs($this->owner)->get('/team/invite')->assertOk()->assertSee('shop.nl')->assertSee('Web developer');

        $this->actingAs($this->owner)->post('/team/invitations', [
            'email' => 'Piet@Example.test', 'name' => 'Piet', 'locale' => 'nl',
            'all_websites' => '0', 'websites' => [$this->shop->id], 'areas' => ['files', 'wordpress'],
        ])->assertRedirect('/team');

        $invitation = TeamInvitation::firstOrFail();
        $this->assertSame('piet@example.test', $invitation->email);
        $this->assertSame([$this->shop->id], $invitation->website_ids);
        $token = null;
        Mail::assertSent(TeamInvitationMail::class, function (TeamInvitationMail $mail) use (&$token) {
            $token = $mail->token;

            return $mail->hasTo('piet@example.test') && $mail->locale === 'nl';
        });
        $this->assertNotSame($token, $invitation->token_hash, 'only the hash is stored');

        // The invitee accepts with a strong password, as a guest.
        auth()->logout();
        $this->get("/team/join/{$token}")->assertOk()->assertSee('Bakkerij Jan')->assertSee('shop.nl');
        $this->post("/team/join/{$token}", ['name' => 'Piet', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post("/team/join/{$token}", ['name' => 'Piet', 'password' => 'Sterk-wachtwoord-1', 'password_confirmation' => 'Sterk-wachtwoord-1'])
            ->assertRedirect('/dashboard');

        $user = User::where('email', 'piet@example.test')->firstOrFail();
        $this->assertSame($this->customer->id, $user->customer_id);
        $this->assertSame('nl', $user->locale);
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['files', 'wordpress'], $user->teamMember->areas);
        $this->assertSame([$this->shop->id], $user->teamMember->websites->pluck('id')->all());
        $this->assertDatabaseCount('team_invitations', 0);
        $this->assertTrue(ActivityLog::where('action', 'team.joined')->where('user_id', $user->id)->exists());

        // The link works once.
        auth()->logout();
        $this->get("/team/join/{$token}")->assertOk()->assertSee('This invitation no longer works');
        $this->post("/team/join/{$token}", ['name' => 'X', 'password' => 'Sterk-wachtwoord-1', 'password_confirmation' => 'Sterk-wachtwoord-1'])->assertNotFound();
    }

    public function test_expired_invitations_and_existing_logins_are_refused(): void
    {
        Mail::fake();
        $this->actingAs($this->owner)->post('/team/invitations', [
            'email' => $this->owner->email, 'locale' => 'en', 'all_websites' => '1', 'areas' => ['files'],
        ])->assertSessionHasErrors('email');

        [$invitation, $token] = app(TeamManager::class)->invite($this->customer, $this->owner, 'late@example.test', null, 'en',
            ['all_websites' => true, 'website_ids' => [], 'areas' => ['mail']]);
        $invitation->update(['expires_at' => now()->subMinute()]);
        auth()->logout();
        $this->get("/team/join/{$token}")->assertSee('This invitation no longer works');
        $this->get('/team/join/'.str_repeat('a', 48))->assertSee('This invitation no longer works');
    }

    public function test_access_choices_are_validated(): void
    {
        Mail::fake();
        $other = Customer::create(['name' => 'Ander', 'email' => 'a@example.test']);
        $foreign = Website::create(['customer_id' => $other->id, 'domain' => 'ander.nl', 'status' => 'active']);

        $post = fn (array $data) => $this->actingAs($this->owner)->post('/team/invitations', $data + ['email' => 'p@example.test', 'locale' => 'en']);
        $post(['all_websites' => '0', 'websites' => [], 'areas' => ['files']])->assertSessionHasErrors('websites');
        $post(['all_websites' => '0', 'websites' => [$foreign->id], 'areas' => ['files']])->assertSessionHasErrors('websites');
        $post(['all_websites' => '1', 'areas' => []])->assertSessionHasErrors('areas');
        $post(['all_websites' => '0', 'websites' => [$this->shop->id], 'areas' => ['backups']])->assertSessionHasErrors('areas');
        $this->assertDatabaseCount('team_invitations', 0);
        $post(['all_websites' => '1', 'areas' => ['backups', 'billing']])->assertSessionHasNoErrors();
    }

    public function test_member_only_reaches_given_websites_and_areas(): void
    {
        $user = $this->member(['files']);

        $this->actingAs($user)->get('/websites')->assertOk()->assertSee('shop.nl')->assertDontSee('blog.nl');
        $this->actingAs($user)->get("/websites/{$this->shop->id}")->assertOk();
        $this->actingAs($user)->get("/websites/{$this->blog->id}")->assertNotFound();
        $this->actingAs($user)->get("/files/{$this->blog->id}")->assertNotFound();

        // Website settings is another area.
        $this->actingAs($user)->put("/websites/{$this->shop->id}", ['php_version' => '8.4'])->assertForbidden();
        // The owner's pages stay with the owner.
        $this->actingAs($user)->get('/team')->assertForbidden();
        $this->actingAs($user)->get('/team/activity')->assertForbidden();
        // Areas without access are closed and gone from the menu.
        $this->actingAs($user)->get('/backups')->assertForbidden();
        $this->actingAs($user)->get('/mail/domains')->assertForbidden();
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee(route('client.files.index'), false)
            ->assertDontSee(route('client.backups.index'), false)->assertDontSee(route('client.team.index'), false);

        // The owner sees everything.
        $this->actingAs($this->owner)->get('/websites')->assertSee('blog.nl');
        $this->actingAs($this->owner)->get('/dashboard')->assertSee(route('client.team.index'), false);
    }

    public function test_mail_and_dns_are_separate_areas_and_follow_the_websites(): void
    {
        $mail = fn (Website $site) => MailDomain::firstOrCreate(['domain' => $site->domain],
            ['customer_id' => $this->customer->id, 'website_id' => $site->id, 'dns_managed' => false]);
        [$shopMail, $blogMail] = [$mail($this->shop), $mail($this->blog)];
        $user = $this->member(['mail']);

        $this->actingAs($user)->get('/mail/domains')->assertOk()->assertSee('shop.nl')->assertDontSee('blog.nl');
        $this->actingAs($user)->get("/mail/domains/{$shopMail->id}")->assertOk();
        $this->actingAs($user)->get("/mail/domains/{$blogMail->id}")->assertNotFound();
        $this->actingAs($user)->post("/mail/domains/{$shopMail->id}/dns/check")->assertForbidden();
    }

    public function test_two_factor_can_be_required_for_team_members(): void
    {
        $user = $this->member(['files']);
        $this->actingAs($this->owner)->put('/team/settings', ['team_require_two_factor' => '1'])->assertRedirect();
        $this->assertTrue((bool) $this->customer->fresh()->team_require_two_factor);

        $this->actingAs($user)->get('/websites')->assertRedirect(route('profile.security'));
        $this->actingAs($user)->get(route('profile.security'))->assertOk();
        // The owner is not forced by this setting.
        $this->actingAs($this->owner)->get('/websites')->assertOk();

        $user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($user)->withSession(['two_factor_passed' => true])->get('/websites')->assertOk();
    }

    public function test_owner_changes_access_and_removes_a_member(): void
    {
        $user = $this->member(['files']);
        $member = $user->teamMember;

        $this->actingAs($this->owner)->get("/team/members/{$member->id}/edit")->assertOk()->assertSee('Webbouwer Piet');
        $this->actingAs($this->owner)->put("/team/members/{$member->id}", ['all_websites' => '1', 'areas' => ['files', 'websites']])->assertRedirect('/team');
        $this->assertTrue($member->fresh()->all_websites);
        $this->actingAs($user)->get('/websites')->assertSee('blog.nl');
        $this->actingAs($user)->put("/websites/{$this->blog->id}", ['php_version' => '8.4'])->assertRedirect();

        // Another customer cannot touch this member.
        $stranger = User::factory()->create(['customer_id' => Customer::create(['name' => 'X', 'email' => 'x@example.test'])->id]);
        $this->actingAs($stranger)->delete("/team/members/{$member->id}")->assertNotFound();

        $this->actingAs($this->owner)->delete("/team/members/{$member->id}")->assertRedirect('/team');
        $this->assertModelMissing($user);
        $this->assertDatabaseCount('team_members', 0);
    }

    public function test_every_change_is_in_the_audit_log_with_who_did_it(): void
    {
        $user = $this->member(['websites']);
        $this->actingAs($user)->put("/websites/{$this->shop->id}", ['php_version' => '8.4'])->assertRedirect();

        $log = ActivityLog::where('action', 'client.change')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($this->customer->id, $log->customer_id);
        $this->assertSame('shop.nl', $log->subject);
        $this->assertSame('Website settings: Changed', $log->label());
        $this->assertStringContainsString('Webbouwer Piet', $log->actor);

        // A form that comes back with errors changed nothing, so it is not logged.
        $this->actingAs($user)->put("/websites/{$this->shop->id}", ['php_version' => '5.6'])->assertSessionHasErrors();
        $this->assertSame(1, ActivityLog::where('action', 'client.change')->count());

        $this->actingAs($this->owner)->get('/team/activity')->assertOk()->assertSee('Webbouwer Piet')->assertSee('Website settings: Changed')->assertSee('shop.nl');

        // After removal the log still says who it was.
        app(TeamManager::class)->remove($user->teamMember);
        $this->actingAs($this->owner)->get('/team/activity')->assertOk()->assertSee('Webbouwer Piet');
        $this->actingAs($this->owner)->get("/team/activity?user={$this->owner->id}")->assertOk()->assertDontSee('Website settings: Changed');
    }

    public function test_pages_render_in_dutch(): void
    {
        $this->member(['files', 'mail']);
        $this->owner->update(['locale' => 'nl']);
        $this->actingAs($this->owner)->get('/team')->assertOk()->assertSee('Teamlid uitnodigen')->assertSee('Mensen met toegang');
        $this->actingAs($this->owner)->get('/team/invite')->assertOk()->assertSee('Webontwikkelaar')->assertSee('Bestanden');
        $this->actingAs($this->owner)->get('/team/activity')->assertOk()->assertSee('Activiteitenlog');
    }

    public function test_admin_sees_team_members_on_the_customer_page(): void
    {
        $this->member(['files']);
        $this->actingAsAdmin();
        $this->get("/admin/customers/{$this->customer->id}")->assertOk()->assertSee('Webbouwer Piet')->assertSee('Team member');
    }
}
