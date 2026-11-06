<?php

namespace App\Services\Team;

use App\Models\TeamMember;
use App\Models\User;
use App\Models\Website;
use App\Support\TeamAreas;
use Illuminate\Support\Facades\DB;

/**
 * What the logged-in client may reach. Owners (a customer login without a
 * team_members row) reach everything of their customer; team members only the
 * areas and websites they were given. Admins are never limited here.
 */
class TeamAccess
{
    private ?int $userId = null;

    private ?TeamMember $member = null;

    private ?array $websiteIds = null;

    public function member(?User $user = null): ?TeamMember
    {
        $user ??= auth()->user();
        if (! $user instanceof User || $user->isAdmin() || ! $user->customer_id) {
            return null;
        }
        if ($this->userId !== $user->id) {
            $this->userId = $user->id;
            $this->member = TeamMember::where('user_id', $user->id)->first();
            $this->websiteIds = null;
        }

        return $this->member;
    }

    public function isOwner(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user instanceof User && ! $user->isAdmin() && $user->customer_id && $this->member($user) === null;
    }

    public function allowsArea(string $area, ?User $user = null): bool
    {
        $member = $this->member($user);

        return $member === null || $member->hasArea($area);
    }

    public function allowsRoute(?string $route, ?User $user = null): bool
    {
        $member = $this->member($user);
        if ($member === null || $route === null || ! str_starts_with($route, 'client.')) {
            return true;
        }
        if (in_array($route, TeamAreas::ALWAYS, true)) {
            return true;
        }
        $area = TeamAreas::areaFor($route);

        return $area !== null && $member->hasArea($area);
    }

    /**
     * The website ids the current team member may see, with the subdomains of
     * those websites; null when nothing is limited (owner, admin, all websites).
     */
    public function scopedWebsiteIds(): ?array
    {
        $member = $this->member();
        if ($member === null || $member->all_websites) {
            return null;
        }
        if ($this->websiteIds === null) {
            $ids = DB::table('team_member_website')->where('team_member_id', $member->id)->pluck('website_id')->all();
            $children = $ids ? Website::withoutGlobalScope(WebsiteScope::class)->whereIn('parent_id', $ids)->pluck('id')->all() : [];
            $this->websiteIds = array_values(array_unique(array_map('intval', [...$ids, ...$children])));
        }

        return $this->websiteIds;
    }

    /** Forget what was looked up, after the member's access changed or between requests. */
    public function forget(): void
    {
        $this->userId = null;
        $this->member = null;
        $this->websiteIds = null;
    }
}
