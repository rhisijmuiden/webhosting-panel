<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks inactive accounts, asks for the two-factor code after login and
 * makes admins set up two-factor authentication before anything else.
 */
class EnsureTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->is_active === false || ($user->customer && $user->customer->status === 'suspended' && ! $user->isAdmin())) {
            Auth::logout();
            $request->session()->invalidate();

            return redirect()->route('login')->withErrors(['email' => __('This account is disabled.')]);
        }

        if ($user->hasTwoFactor() && ! $request->session()->get('two_factor_passed')
            && ! $request->routeIs('two-factor.*', 'logout', 'locale.switch')) {
            return redirect()->route('two-factor.challenge');
        }

        if ($user->isAdmin() && config('panel.require_admin_2fa') && ! $user->hasTwoFactor()
            && ! $request->routeIs('profile.*', 'locale.switch', 'logout')) {
            return redirect()->route('profile.security')->with('warning', __('Set up two-factor authentication to continue.'));
        }

        return $next($request);
    }
}
