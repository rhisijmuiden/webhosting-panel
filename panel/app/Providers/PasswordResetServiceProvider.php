<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Passwords of panel logins: "Forgot password?" on the login page, and the
 * administrator setting a password or mailing a reset link for a customer.
 * Mail goes out through the panel's own SMTP settings (MailConfigurator).
 */
class PasswordResetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        ResetPassword::toMailUsing(function (User $user, string $token) {
            $minutes = (int) config('auth.passwords.users.expire', 60);

            return (new MailMessage)
                ->subject(__('Reset your password for :app', ['app' => config('app.name')]))
                ->markdown('mail.password-reset', [
                    'user' => $user,
                    'url' => route('password.reset', ['token' => $token, 'email' => $user->email]),
                    'minutes' => $minutes,
                ]);
        });

        ActivityLog::labels([
            'auth.password_reset_requested' => 'Password reset requested',
            'auth.password_reset' => 'Password reset with the e-mail link',
            'customer.user_password_set' => 'Password set by administrator',
            'customer.user_reset_link_sent' => 'Password reset link sent by administrator',
        ]);

        // Asking for a link: a few per address, so nobody floods a mailbox.
        RateLimiter::for('password-forgot', fn (Request $request) => [
            Limit::perMinute(3)->by('pw-forgot|'.strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(10)->by('pw-forgot|'.$request->ip()),
        ]);
        // Saving the new password with a token.
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by('pw-reset|'.$request->ip()));

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group(base_path('routes/password-reset.php'));
        }
    }
}
