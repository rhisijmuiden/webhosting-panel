<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\Totp;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user(), 'locales' => Locales::available()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users')->ignore($user->id)],
            'locale' => ['required', Rule::in(array_keys(Locales::available()))],
        ]);
        $user->update($data);
        ActivityLog::record('profile.updated');

        return back()->with('status', __('Profile saved.'));
    }

    public function password(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->uncompromised()],
        ]);
        $request->user()->update(['password' => $request->input('password')]);
        ActivityLog::record('profile.password_changed');

        return back()->with('status', __('Password changed.'));
    }

    public function security(Request $request, Totp $totp): View
    {
        $user = $request->user();
        $pending = $request->session()->get('two_factor_pending');

        return view('profile.security', [
            'user' => $user,
            'pendingSecret' => $pending,
            'otpUri' => $pending ? $totp->uri($pending, $user->email, config('app.name')) : null,
        ]);
    }

    public function enableTwoFactor(Request $request, Totp $totp): RedirectResponse
    {
        $request->session()->put('two_factor_pending', $totp->generateSecret());

        return redirect()->route('profile.security');
    }

    public function confirmTwoFactor(Request $request, Totp $totp): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $secret = $request->session()->get('two_factor_pending');
        if (! $secret || ! $totp->verify($secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('The code is not valid.')]);
        }

        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('two_factor_pending');
        $request->session()->put('two_factor_passed', true);
        ActivityLog::record('profile.two_factor_enabled');

        return redirect()->route('profile.security')->with('status', __('Two-factor authentication is on.'));
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $user = $request->user();
        if ($user->isAdmin() && config('panel.require_admin_2fa')) {
            throw ValidationException::withMessages(['password' => __('Admins must keep two-factor authentication on.')]);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        ActivityLog::record('profile.two_factor_disabled');

        return redirect()->route('profile.security')->with('status', __('Two-factor authentication is off.'));
    }
}
