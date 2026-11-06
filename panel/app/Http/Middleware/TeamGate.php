<?php

namespace App\Http\Middleware;

use App\Services\Team\TeamAccess;
use App\Services\Team\TeamAudit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every client panel page (through the "client" middleware): keeps
 * team members inside the areas they were given, asks for two-factor
 * authentication when the owner wants that, and writes the audit log.
 */
class TeamGate
{
    public function __construct(private readonly TeamAccess $access, private readonly TeamAudit $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->access->forget();
        $user = $request->user();
        $member = $this->access->member($user);

        if ($member !== null) {
            if ($member->customer?->team_require_two_factor && ! $user->hasTwoFactor()) {
                return redirect()->route('profile.security')
                    ->with('warning', __('The owner of this account asks every team member to use two-factor authentication. Set it up to continue.'));
            }
            if (! $this->access->allowsRoute($request->route()?->getName(), $user)) {
                abort(403, __('You have no access to this part of the panel. Ask the owner of the account for access.'));
            }
        }

        $before = $this->audit->count();
        $response = $next($request);
        $this->audit->after($request, $response, $before);

        return $response;
    }
}
