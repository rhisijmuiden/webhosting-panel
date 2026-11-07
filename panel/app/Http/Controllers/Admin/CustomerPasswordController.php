<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Services\Auth\PasswordChanger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Throwable;

/** The administrator helps a customer's login: choose a password, or mail a reset link. */
class CustomerPasswordController extends Controller
{
    public function set(Request $request, Customer $customer, User $user, PasswordChanger $passwords): RedirectResponse
    {
        abort_unless($user->customer_id === $customer->id, 404);
        $data = $request->validateWithBag('password_set', [
            'password' => ['required', 'string', 'confirmed', Password::min(12)],
        ]);

        $passwords->change($user, $data['password']);
        ActivityLog::record('customer.user_password_set', $user->email, customerId: $customer->id);

        return back()->with('status', __('Password set for :email. They are logged out on all devices.', ['email' => $user->email]));
    }

    public function link(Customer $customer, User $user, PasswordChanger $passwords): RedirectResponse
    {
        abort_unless($user->customer_id === $customer->id, 404);

        try {
            $passwords->sendLink($user);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['password_link' => __('The e-mail could not be sent: :error', ['error' => $e->getMessage()])]);
        }
        ActivityLog::record('customer.user_reset_link_sent', $user->email, customerId: $customer->id);

        return back()->with('status', __('Reset link sent to :email. The link works for :minutes minutes.', [
            'email' => $user->email, 'minutes' => (int) config('auth.passwords.users.expire', 60),
        ]));
    }
}
