<?php

namespace App\Services;

use App\Mail\TrafficLimitReached;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteTraffic;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Data traffic per website (from the Nginx traffic log, read by the agent) and the
 * monthly traffic limit of the package: at 100 % the admins get an e-mail, and with
 * "suspend" the customer's websites are suspended until the next month or until the
 * admin lifts it.
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

    /** Bring back the websites that were suspended for the traffic limit (new month, or the admin lifts it). */
    public function unsuspend(Customer $customer): int
    {
        $count = 0;
        foreach ($customer->websites()->where('status', 'suspended')->where('last_error', self::SUSPENDED)->get() as $website) {
            try {
                $this->agent->call('site.unsuspend', ['domain' => $website->domain], 120);
                $website->update(['status' => 'active', 'last_error' => null]);
                $count++;
            } catch (AgentException $e) {
                ActivityLog::record('website.unsuspend_failed', $website->domain, ['error' => $e->getMessage()], $customer->id);
            }
        }
        if ($count) {
            ActivityLog::record('customer.traffic_suspension_lifted', $customer->displayName(), ['websites' => $count], $customer->id);
        }

        return $count;
    }

    public function checkLimits(string $month): void
    {
        // A new month: the traffic counter starts at zero again.
        Customer::whereNotNull('traffic_alert_month')->where('traffic_alert_month', '!=', $month)->get()
            ->each(function (Customer $customer) {
                $this->unsuspend($customer);
                $customer->forceFill(['traffic_alert_month' => null])->saveQuietly();
            });

        $customers = Customer::with('package')->whereHas('package', fn ($q) => $q->whereNotNull('traffic_gb_month'))->get();
        foreach ($customers as $customer) {
            if ($customer->traffic_alert_month === $month) {
                continue;
            }
            $used = $customer->trafficBytes($month);
            $limit = $customer->package->traffic_gb_month * 1024 ** 3;
            if ($used < $limit) {
                continue;
            }
            $customer->forceFill(['traffic_alert_month' => $month])->saveQuietly();
            $suspend = $customer->package->traffic_action === 'suspend';
            ActivityLog::record('customer.traffic_limit_reached', $customer->displayName(),
                ['used_bytes' => $used, 'limit_gb' => $customer->package->traffic_gb_month, 'suspended' => $suspend], $customer->id);
            if ($suspend) {
                foreach ($customer->websites()->where('status', 'active')->get() as $website) {
                    try {
                        $this->agent->call('site.suspend', ['domain' => $website->domain], 120);
                        $website->update(['status' => 'suspended', 'last_error' => self::SUSPENDED]);
                    } catch (AgentException $e) {
                        ActivityLog::record('website.suspend_failed', $website->domain, ['error' => $e->getMessage()], $customer->id);
                    }
                }
            }
            try {
                $admins = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->pluck('email')->all();
                if ($admins) {
                    Mail::to($admins)->send(new TrafficLimitReached($customer, $used, $suspend));
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
