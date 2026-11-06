<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Quick view: the common customer tasks, made for a phone. Find a customer or
 * domain, see what needs attention, suspend or reactivate a customer and help
 * a customer who cannot log in (new password, two-factor off).
 */
class QuickController extends Controller
{
    /** Reason on websites that the administrator suspended together with their customer. */
    public const SUSPENDED = 'Suspended by administrator';

    public function index(): View
    {
        $attention = Website::with('customer')
            ->where(fn ($q) => $q->whereIn('status', ['suspended', 'failed'])
                ->orWhereIn('ssl_status', ['failed', 'error', 'expired']))
            ->orderBy('domain')->limit(20)->get();

        $recentIds = ActivityLog::whereNotNull('customer_id')->latest('created_at')->limit(200)
            ->pluck('customer_id')->unique()->take(6)->values();
        $recent = Customer::withCount(['websites', 'subscriptions'])->whereIn('id', $recentIds)->get()
            ->sortBy(fn ($c) => $recentIds->search($c->id))->values();

        return view('admin.quick', [
            'attention' => $attention,
            'recent' => $recent,
            'stats' => [
                'customers' => Customer::count(),
                'websites' => Website::count(),
                'suspended' => Customer::where('status', 'suspended')->count(),
            ],
        ]);
    }

    /** Customers and domains that match, as JSON for the live search. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';

        $customers = Customer::withCount('websites')
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('company', 'like', $like)
                ->orWhere('email', 'like', $like)->orWhere('crm_id', 'like', $like))
            ->orderBy('name')->limit(8)->get()
            ->map(fn (Customer $c) => [
                'type' => 'customer',
                'title' => $c->displayName(),
                'sub' => $c->email.' · '.trans_choice(':count website|:count websites', $c->websites_count),
                'status' => $c->status,
                'url' => route('admin.customers.show', $c),
            ]);

        $websites = Website::with('customer')->where('domain', 'like', $like)
            ->orderBy('domain')->limit(8)->get()
            ->map(fn (Website $w) => [
                'type' => 'website',
                'title' => $w->domain,
                'sub' => $w->customer?->displayName() ?? '',
                'status' => $w->status,
                'url' => route('admin.websites.show', $w),
            ]);

        return response()->json(['results' => $customers->concat($websites)->values()]);
    }

    /** Suspend a customer: their logins are blocked and their websites go offline. */
    public function suspend(Customer $customer, AgentClient $agent): RedirectResponse
    {
        $customer->update(['status' => 'suspended']);
        $failed = 0;
        foreach ($customer->websites()->where('status', 'active')->get() as $website) {
            try {
                $agent->call('site.suspend', ['domain' => $website->domain], 120);
                $website->update(['status' => 'suspended', 'last_error' => self::SUSPENDED]);
            } catch (AgentException $e) {
                $failed++;
                ActivityLog::record('website.suspend_failed', $website->domain, ['error' => $e->getMessage()], $customer->id);
            }
        }
        ActivityLog::record('customer.suspended', $customer->displayName(), customerId: $customer->id);

        return back()->with('status', __('Customer suspended.'))
            ->withErrors($failed ? ['customer' => __('Some websites could not be suspended. See the activity log.')] : []);
    }

    /** Reactivate a customer: logins work again and the websites that were suspended with them come back. */
    public function resume(Customer $customer, AgentClient $agent): RedirectResponse
    {
        $customer->update(['status' => 'active']);
        foreach ($customer->websites()->where('status', 'suspended')->where('last_error', self::SUSPENDED)->get() as $website) {
            try {
                $agent->call('site.unsuspend', ['domain' => $website->domain], 120);
                $website->update(['status' => 'active', 'last_error' => null]);
            } catch (AgentException $e) {
                ActivityLog::record('website.unsuspend_failed', $website->domain, ['error' => $e->getMessage()], $customer->id);
            }
        }
        ActivityLog::record('customer.reactivated', $customer->displayName(), customerId: $customer->id);

        return back()->with('status', __('Customer reactivated.'));
    }

    /** Give a login a new random password, shown once to the administrator. */
    public function password(Customer $customer, User $user): RedirectResponse
    {
        abort_unless($user->customer_id === $customer->id, 404);
        $password = self::generatePassword();
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        ActivityLog::record('customer.user_password_reset', $user->email, customerId: $customer->id);

        return back()->with('status', __('New password set for :email.', ['email' => $user->email]))
            ->with('new_password', ['email' => $user->email, 'password' => $password]);
    }

    /** Turn off two-factor authentication for a login that lost its phone. */
    public function twoFactor(Customer $customer, User $user): RedirectResponse
    {
        abort_unless($user->customer_id === $customer->id, 404);
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        ActivityLog::record('customer.user_two_factor_reset', $user->email, customerId: $customer->id);

        return back()->with('status', __('Two-factor authentication is off for :email.', ['email' => $user->email]));
    }

    /** 16 characters without look-alikes (0/O, 1/l/I), easy to read out over the phone. */
    public static function generatePassword(int $length = 16): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
