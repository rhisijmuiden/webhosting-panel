<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Auth\PasswordChanger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;
use Throwable;

/** "Forgot password?": request a link by e-mail and choose a new password with it. */
class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request, PasswordChanger $passwords): RedirectResponse
    {
        $email = Str::lower(trim((string) $request->validate(['email' => ['required', 'email', 'max:255']])['email']));

        // Same answer whether the address exists or not, so nobody can find out who has a login.
        $user = User::where('email', $email)->where('is_active', true)->first();
        if ($user && ! ($user->customer?->status === 'suspended' && ! $user->isAdmin())) {
            try {
                $passwords->sendLink($user);
                ActivityLog::record('auth.password_reset_requested', $user->email, customerId: $user->customer_id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return back()->with('status', __('If :email has a login, we sent it a link to choose a new password. Check your inbox (and spam folder).', ['email' => $email]));
    }

    public function show(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function reset(Request $request, PasswordChanger $passwords): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()],
        ]);

        $done = null;
        $status = Password::broker()->reset(
            ['email' => Str::lower(trim($data['email'])), 'password' => $data['password'], 'token' => $data['token']],
            function (User $user, string $password) use ($passwords, &$done) {
                $passwords->change($user, $password);
                $done = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $done) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __('This reset link is invalid or has expired. Request a new one.')]);
        }
        ActivityLog::record('auth.password_reset', $done->email, customerId: $done->customer_id);

        return redirect()->route('login')->with('status', __('Your password is changed. Log in with your new password.'));
    }
}
