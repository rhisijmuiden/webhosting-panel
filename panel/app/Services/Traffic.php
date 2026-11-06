<?php

namespace App\Services;

use App\Mail\TrafficLimitReached;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteTraffic;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Data traffic per website (from the Nginx traffic log, read by the agent) and the
 * monthly traffic limit of the subscription's package: at 100 % the admins get an
 * e-mail, and with "suspend" that website is suspended until the next month or
 * until the admin lifts it.
 */
class Traffic
{
    public function __construct(private readonly AgentClient $agent) {}

    public function collect(): void
    {
        $report = $this->agent->call('traffic.report', [], 120);
        $month = $report['month'] ?? now()->utc()->format('Y-m');
        $sites = Website::pluck('id', 'domain');
        foreach ($report['sites'] ?? [] as $domain => $bytes) {
            if ($id = $sites[$domain] ?? null) {
                WebsiteTraffic::updateOrCreate(['website_id' => $id, 'month' => $month], ['bytes' => (int) $bytes]);
            }
        }
        $this->checkLimits($month);
    }

    public const SUSPENDED = 'Suspended: data traffic limit reached';

    /** Bring back the websites of a customer that were suspended for the traffic limit (admin lifts it). */
    public function unsuspend(Customer $customer): int
    {
        $count = 0;
        foreach (Subscription::ownedBy($customer)->where('status', 'suspended')->where('suspended_reason', self::SUSPENDED)->get() as $subscription) {
            $count += (int) $this->unsuspendSubscription($subscription);
        }
        if ($count) {
            ActivityLog::record('customer.traffic_suspension_lifted', $customer->displayName(), ['websites' => $count], $customer->id);
        }

        return $count;
    }

    public function unsuspendSubscription(Subscription $subscription): bool
    {
        if ($subscription->suspended_reason !== self::SUSPENDED) {
            return false;
        }
        try {
            app(SubscriptionManager::class)->activate($subscription);

            return true;
        } catch (AgentException $e) {
            ActivityLog::record('website.unsuspend_failed', $subscription->website?->domain, ['error' => $e->getMessage()], $subscription->website?->customer_id);

            return false;
        }
    }

    /** The traffic limit of every subscription's package (per website, like Plesk). */
    public function checkLimits(string $month): void
    {
        // A new month: the traffic counter starts at zero again.
        Subscription::whereNotNull('traffic_alert_month')->where('traffic_alert_month', '!=', $month)->get()
            ->each(function (Subscription $subscription) {
                $this->unsuspendSubscription($subscription);
                $subscription->forceFill(['traffic_alert_month' => null])->saveQuietly();
            });

        $subscriptions = Subscription::with('package', 'website.customer')
            ->whereHas('package', fn ($q) => $q->whereNotNull('traffic_gb_month'))->get();
        foreach ($subscriptions as $subscription) {
            $website = $subscription->website;
            if (! $website || $subscription->traffic_alert_month === $month) {
                continue;
            }
            $used = $website->trafficBytes($month);
            $limit = $subscription->package->traffic_gb_month * 1024 ** 3;
            if ($used < $limit) {
                continue;
            }
            $subscription->forceFill(['traffic_alert_month' => $month])->saveQuietly();
            $suspend = $subscription->package->traffic_action === 'suspend';
            ActivityLog::record('subscription.traffic_limit_reached', $website->domain,
                ['used_bytes' => $used, 'limit_gb' => $subscription->package->traffic_gb_month, 'suspended' => $suspend], $website->customer_id);
            if ($suspend && $subscription->isActive()) {
                try {
                    app(SubscriptionManager::class)->suspend($subscription, self::SUSPENDED);
                } catch (AgentException $e) {
                    ActivityLog::record('website.suspend_failed', $website->domain, ['error' => $e->getMessage()], $website->customer_id);
                }
            }
            try {
                $admins = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->pluck('email')->all();
                if ($admins) {
                    Mail::to($admins)->send(new TrafficLimitReached($subscription, $used, $suspend));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
