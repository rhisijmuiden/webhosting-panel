<?php

namespace App\Services\Team;

use App\Mail\TeamInvitationMail;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\TeamInvitation;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Website;
use App\Support\TeamAreas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Invites, changes and removes team members of a customer. */
class TeamManager
{
    /** Areas that reach data of every website of the customer, so they need "all websites". */
    public const NEEDS_ALL_WEBSITES = ['backups', 'billing'];

    public function __construct(private readonly TeamAccess $access) {}

    /**
     * Normalise an access choice: known areas only, websites of this customer only.
     *
     * @return array{all_websites: bool, website_ids: list<int>, areas: list<string>}
     */
    public function access(Customer $customer, bool $allWebsites, array $websiteIds, array $areas): array
    {
        $areas = array_values(array_intersect(TeamAreas::keys(), $areas));
        $websiteIds = $allWebsites ? [] : Website::withoutGlobalScope(WebsiteScope::class)
            ->where('customer_id', $customer->id)->whereIn('id', array_map('intval', $websiteIds))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        $errors = [];
        if ($areas === []) {
            $errors['areas'] = __('Choose at least one area.');
        }
        if (! $allWebsites && $websiteIds === []) {
            $errors['websites'] = __('Choose at least one website, or all websites.');
        }
        if (! $allWebsites && ($wide = array_intersect($areas, self::NEEDS_ALL_WEBSITES))) {
            $errors['areas'] = __(':areas can only be given together with access to all websites.', [
                'areas' => collect($wide)->map(fn ($a) => __(TeamAreas::all()[$a]['label']))->join(', '),
            ]);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['all_websites' => $allWebsites, 'website_ids' => $websiteIds, 'areas' => $areas];
    }

    /** @return array{0: TeamInvitation, 1: string} the invitation and its plain token (only in the mail). */
    public function invite(Customer $customer, User $by, string $email, ?string $name, string $locale, array $access): array
    {
        $email = Str::lower(trim($email));
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => __('This e-mail address already has a login. Use another address.')]);
        }
        if ($this->memberCount($customer) >= (int) config('team.max_members', 25)) {
            throw ValidationException::withMessages(['email' => __('Your team is full.')]);
        }

        TeamInvitation::where('customer_id', $customer->id)->where('email', $email)->delete();
        $token = Str::random(48);
        $invitation = TeamInvitation::create([
            'customer_id' => $customer->id,
            'invited_by' => $by->id,
            'email' => $email,
            'name' => $name ?: null,
            'locale' => $locale,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('team.invite_days', 7)),
        ] + $access);

        $this->send($invitation, $token);
        ActivityLog::record('team.invited', $email, ['areas' => $access['areas']], $customer->id);

        return [$invitation, $token];
    }

    /** Send the invitation again with a fresh link (the old link stops working). */
    public function resend(TeamInvitation $invitation): void
    {
        $token = Str::random(48);
        $invitation->update([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays((int) config('team.invite_days', 7)),
        ]);
        $this->send($invitation, $token);
        ActivityLog::record('team.invite_resent', $invitation->email, [], $invitation->customer_id);
    }

    public function revoke(TeamInvitation $invitation): void
    {
        $invitation->delete();
        ActivityLog::record('team.invite_revoked', $invitation->email, [], $invitation->customer_id);
    }

    /** The invitee accepts: their login is made with exactly the access of the invitation. */
    public function accept(TeamInvitation $invitation, string $name, string $password): User
    {
        if (User::where('email', $invitation->email)->exists()) {
            throw ValidationException::withMessages(['email' => __('This e-mail address already has a login. Use another address.')]);
        }

        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
                'role' => User::ROLE_CLIENT,
                'customer_id' => $invitation->customer_id,
                'locale' => $invitation->locale,
                'is_active' => true,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $member = TeamMember::create([
                'user_id' => $user->id,
                'customer_id' => $invitation->customer_id,
                'invited_by' => $invitation->invited_by,
                'all_websites' => $invitation->all_websites,
                'areas' => $invitation->areas,
            ]);
            $member->websites()->sync($invitation->all_websites ? [] : $invitation->website_ids);
            $invitation->delete();

            ActivityLog::create([
                'user_id' => $user->id,
                'customer_id' => $invitation->customer_id,
                'action' => 'team.joined',
                'subject' => $user->email,
                'ip' => request()?->ip(),
            ]);
            $this->access->forget();

            return $user;
        });
    }

    public function update(TeamMember $member, array $access): void
    {
        $member->update(['all_websites' => $access['all_websites'], 'areas' => $access['areas']]);
        $member->websites()->sync($access['all_websites'] ? [] : $access['website_ids']);
        $this->access->forget();
        ActivityLog::record('team.updated', $member->user->email, ['areas' => $access['areas'], 'all_websites' => $access['all_websites']], $member->customer_id);
    }

    /** Remove the member: their login is deleted, the audit log keeps their name. */
    public function remove(TeamMember $member): void
    {
        $user = $member->user;
        ActivityLog::record('team.removed', $user->email, ['name' => $user->name], $member->customer_id);
        rescue(fn () => DB::table('sessions')->where('user_id', $user->id)->delete(), report: false);
        $user->delete();
        $this->access->forget();
    }

    public function memberCount(Customer $customer): int
    {
        return TeamMember::where('customer_id', $customer->id)->count()
            + TeamInvitation::where('customer_id', $customer->id)->count();
    }

    private function send(TeamInvitation $invitation, string $token): void
    {
        Mail::to($invitation->email)->locale($invitation->locale)->send(new TeamInvitationMail($invitation, $token));
    }
}
