<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Give a login a new password and end everything that still used the old one. */
class PasswordChanger
{
    public function change(User $user, string $password, ?string $keepSessionId = null): void
    {
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        // Unused reset links stop working once the password changed.
        Password::broker()->deleteToken($user);

        // Log the login out on every device ("keep me logged in" cookies end with the remember token).
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)
                ->when($keepSessionId, fn ($q) => $q->where('id', '!=', $keepSessionId))->delete();
        }

        event(new PasswordReset($user));
    }

    /** Mail a reset link to this login, also when one was sent a minute ago. */
    public function sendLink(User $user): void
    {
        $user->sendPasswordResetNotification(Password::broker()->createToken($user));
    }
}
