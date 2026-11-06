<?php

namespace App\Services;

use App\Jobs\ApplySubscriptionLimits;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\HostingPackage;
use App\Models\Subscription;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every change to a subscription (one website on one package) goes through here,
 * so the admin list, the bulk actions, the CRM API and the traffic limit behave the same.
 */
class SubscriptionManager
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Another package (null: none, everything unlimited). The disk quota follows the
     * package unless $quota is false (the CRM API sets its own); the resource jail,
     * PHP workers and database connections are put in place on the queue.
     */
    public function changePackage(Subscription $subscription, ?HostingPackage $package, bool $quota = true): void
    {
        if ($subscription->hosting_package_id === $package?->id) {
            return;
        }
        $old = $subscription->package?->name;
        $subscription->package()->associate($package);
        $subscription->save();
        $website = $subscription->website;
        if ($quota && $website && ! in_array($website->status, ['provisioning', 'removing'], true)) {
            $mb = $package?->disk_mb;
            if ((int) $mb !== (int) $website->disk_quota_mb) {
                try {
                    $this->agent->call('site.quota', ['domain' => $website->domain, 'quota_mb' => (int) $mb], 120);
                    $website->update(['disk_quota_mb' => $mb]);
                } catch (AgentException $e) {
                    ActivityLog::record('subscription.quota_failed', $website->domain, ['error' => $e->getMessage()], $website->customer_id);
                }
            }
        }
        ActivityLog::record('subscription.package_changed', $website?->domain, ['from' => $old, 'to' => $package?->name], $website?->customer_id);
        ApplySubscriptionLimits::dispatch($subscription->fresh());
    }

    /**
     * Give the subscription to another customer. Everything of the website that
     * carries a customer (mail domain, aliases, databases, apps, CRM subscription, ...)
     * moves along; the files, system user and resource jail stay as they are.
     */
    public function changeOwner(Subscription $subscription, Customer $customer): void
    {
        $website = $subscription->website;
        if (! $website || $website->customer_id === $customer->id) {
            return;
        }
        $from = $website->customer;
        DB::transaction(function () use ($website, $customer) {
            $website->update(['customer_id' => $customer->id]);
            foreach (self::linkedTables() as $table) {
                DB::table($table)->where('website_id', $website->id)->update(['customer_id' => $customer->id]);
            }
            // Mail domains that are an alias of this website's mail domain.
            if (Schema::hasColumns('mail_domains', ['alias_of_id', 'customer_id', 'website_id'])) {
                $ids = DB::table('mail_domains')->where('website_id', $website->id)->pluck('id');
                if ($ids->isNotEmpty()) {
                    DB::table('mail_domains')->whereIn('alias_of_id', $ids)->update(['customer_id' => $customer->id]);
                }
            }
        });
        ActivityLog::record('subscription.owner_changed', $website->domain, ['from' => $from?->displayName(), 'to' => $customer->displayName()], $customer->id);
        if ($from) {
            ActivityLog::record('subscription.owner_changed', $website->domain, ['from' => $from->displayName(), 'to' => $customer->displayName()], $from->id);
        }
    }

    /** Suspend: the website shows the "temporarily unavailable" page and SFTP closes. */
    public function suspend(Subscription $subscription, ?string $reason = null): void
    {
        $website = $subscription->website;
        if ($website && $website->status === 'active') {
            $this->agent->call('site.suspend', ['domain' => $website->domain], 120);
            $website->update(['status' => 'suspended', 'last_error' => $reason]);
        }
        $subscription->update(['status' => 'suspended', 'suspended_reason' => $reason]);
        ActivityLog::record('subscription.suspended', $website?->domain, array_filter(['reason' => $reason]), $website?->customer_id);
    }

    public function activate(Subscription $subscription): void
    {
        $website = $subscription->website;
        if ($website && $website->status === 'suspended') {
            $this->agent->call('site.unsuspend', ['domain' => $website->domain], 120);
            $website->update(['status' => 'active', 'last_error' => null]);
        }
        $subscription->update(['status' => 'active', 'suspended_reason' => null]);
        ActivityLog::record('subscription.activated', $website?->domain, customerId: $website?->customer_id);
    }

    public function setExpiry(Subscription $subscription, ?string $date): void
    {
        $subscription->update(['expires_on' => $date ?: null]);
        ActivityLog::record('subscription.expiry_changed', $subscription->website?->domain, ['expires_on' => $date ?: null], $subscription->website?->customer_id);
    }

    /** Suspend the active subscriptions whose expiry date has passed. Returns how many. */
    public function expire(): int
    {
        $count = 0;
        Subscription::with('website')->where('status', 'active')->whereNotNull('expires_on')
            ->whereDate('expires_on', '<', now()->toDateString())->get()
            ->each(function (Subscription $s) use (&$count) {
                try {
                    $this->suspend($s, Subscription::EXPIRED);
                    $count++;
                } catch (AgentException $e) {
                    ActivityLog::record('subscription.suspend_failed', $s->website?->domain, ['error' => $e->getMessage()], $s->website?->customer_id);
                }
            });

        return $count;
    }

    /** Tables (of the panel and its packages) that store both website_id and customer_id. */
    private static function linkedTables(): array
    {
        $tables = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['name'];
            if (in_array($name, ['subscriptions', 'websites'], true)) {
                continue;
            }
            if (Schema::hasColumns($name, ['website_id', 'customer_id'])) {
                $tables[] = $name;
            }
        }

        return $tables;
    }
}
