<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! $request->user()->hasTwoFactor() || $request->session()->get('two_factor_passed')) {
            return redirect()->route('home');
        }

        return view('auth.two-factor');
    }

    public function verify(Request $request, Totp $totp): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->hasTwoFactor() || ! $totp->verify($user->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => __('The code is not valid.')]);
        }

        $request->session()->put('two_factor_passed', true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
