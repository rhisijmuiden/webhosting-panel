<?php

namespace App\Auth;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Admins logging in from a trusted address do not get the two-factor
 * challenge. The session remembers why it passed, so the code is asked
 * again as soon as the same session shows up from another address.
 */
final class TrustedIpTwoFactor
{
    public const VIA = 'trusted_ip';

    public function __construct(private readonly TrustedNetworks $networks) {}

    public function skips(User $user, Request $request): bool
    {
        return $user->isAdmin() && $user->hasTwoFactor() && $this->networks->contains((string) $request->ip());
    }

    public function pass(Request $request): void
    {
        $request->session()->put(['two_factor_passed' => true, 'two_factor_via' => self::VIA]);
        ActivityLog::record('auth.two_factor_skipped', $request->ip(), ['reason' => self::VIA]);
    }

    /** True when the session passed only because of an address it no longer comes from. */
    public function lapsed(Request $request): bool
    {
        return $request->session()->get('two_factor_via') === self::VIA
            && ! $this->networks->contains((string) $request->ip());
    }
}
