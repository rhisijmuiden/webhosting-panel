<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Support;

use App\Models\Customer;
use App\Models\HostingPackage;
use Rhis\CrmApi\Models\CrmJob;
use Rhis\CrmApi\Models\Order;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Models\Webhook;
use Rhis\CrmApi\Models\WebhookDelivery;

/** Turns models into the JSON shapes of docs/api/crm-api.openapi.yaml. */
final class Present
{
    public static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return is_string($value) ? (json_decode($value, true) ?: []) : [];
    }

    /** Effective limits of a plan: panel columns plus the extra CRM limits. */
    public static function planLimits(HostingPackage $p): array
    {
        $unlimited = fn ($v, $none) => ($v === null || (int) $v === $none) ? null : (int) $v;

        return array_merge(self::json($p->crm_limits), [
            'disk_mb' => $unlimited($p->disk_mb, 0),
            'domains' => $unlimited($p->max_websites, 1_000_000),
            'mailboxes' => $unlimited($p->max_mailboxes, 1_000_000),
            'databases' => $unlimited($p->max_databases, 1_000_000),
            'cron_jobs' => $unlimited($p->max_cronjobs, 1_000_000),
            'traffic_gb_month' => $p->traffic_gb_month,
            'php_workers' => $p->php_workers,
        ]);
    }

    public static function plan(HostingPackage $p): array
    {
        return [
            'id' => (string) $p->id,
            'code' => $p->crm_id,
            'name' => $p->name,
            'description' => $p->description,
            'limits' => self::planLimits($p),
            'features' => array_merge(self::json($p->crm_features), ['ssh_access' => (bool) $p->allow_ssh]),
            'active' => (bool) $p->is_active,
            'subscriptions_count' => Subscription::where('hosting_package_id', $p->id)->whereNot('status', 'terminated')->count(),
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    public static function customer(Customer $c): array
    {
        $user = $c->users()->orderBy('id')->first();

        return [
            'id' => (string) $c->id,
            'external_id' => $c->crm_id,
            'name' => $c->name,
            'company' => $c->company,
            'email' => $c->email,
            'phone' => $c->phone,
            'locale' => $c->locale,
            'address' => (object) self::json($c->address),
            'username' => $user?->email,
            'subscriptions_count' => Subscription::where('customer_id', $c->id)->whereNot('status', 'terminated')->count(),
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    public static function subscription(Subscription $s): array
    {
        $site = $s->website;
        $limits = array_merge(self::planLimits($s->package), array_filter($s->limit_overrides ?? [], fn ($v) => $v !== null));

        return [
            'id' => $s->id,
            'external_id' => $s->external_id,
            'customer_id' => (string) $s->customer_id,
            'plan_id' => (string) $s->hosting_package_id,
            'primary_domain' => $s->primary_domain,
            'domains' => $site ? array_values(array_unique([$site->domain, ...($site->aliases ?? [])])) : [$s->primary_domain],
            'status' => $s->status,
            'suspension' => $s->suspension,
            'php_version' => $site?->php_version ?? $s->php_version,
            'limits' => $limits,
            'server' => gethostname() ?: null,
            'system_user' => $site?->system_user,
            'created_at' => $s->created_at?->toIso8601String(),
            'updated_at' => $s->updated_at?->toIso8601String(),
            'terminated_at' => $s->terminated_at?->toIso8601String(),
        ];
    }

    public static function job(CrmJob $j): array
    {
        return [
            'id' => $j->id, 'type' => $j->type, 'status' => $j->status, 'progress' => $j->progress,
            'step' => $j->step, 'error' => $j->error, 'subscription_id' => $j->subscription_id,
            'created_at' => $j->created_at?->toIso8601String(), 'finished_at' => $j->finished_at?->toIso8601String(),
        ];
    }

    public static function usage(Subscription $s): array
    {
        $site = $s->website;
        $limits = self::subscription($s)['limits'];

        return [
            'subscription_id' => $s->id,
            'external_id' => $s->external_id,
            'measured_at' => ($site?->updated_at ?? now())->toIso8601String(),
            'disk_mb' => ['used' => (int) ($site?->disk_used_mb ?? 0), 'limit' => $limits['disk_mb'] ?? null],
            'domains' => ['used' => $site ? 1 : 0, 'limit' => $limits['domains'] ?? null],
            'traffic_gb_month' => [
                'used' => $site ? round((int) \App\Models\WebsiteTraffic::where('website_id', $site->id)
                    ->where('month', now()->utc()->format('Y-m'))->value('bytes') / 1024 ** 3, 2) : 0,
                'limit' => $limits['traffic_gb_month'] ?? null,
            ],
        ];
    }

    public static function webhook(Webhook $w): array
    {
        $last = $w->lastDelivery();

        return [
            'id' => $w->id, 'url' => $w->url, 'events' => $w->events, 'description' => $w->description,
            'created_at' => $w->created_at?->toIso8601String(),
            'last_delivery' => $last ? self::delivery($last) : null,
        ];
    }

    public static function delivery(WebhookDelivery $d): array
    {
        return [
            'id' => $d->id, 'event' => $d->event, 'status' => $d->status, 'http_status' => $d->http_status,
            'attempts' => $d->attempts, 'delivered_at' => $d->delivered_at?->toIso8601String(),
        ];
    }

    public static function order(Order $o): array
    {
        return [
            'id' => $o->id, 'external_id' => $o->external_id, 'status' => $o->status,
            'customer_id' => (string) $o->customer_id, 'customer_external_id' => $o->customer?->crm_id,
            'subscription_id' => $o->subscription_id, 'domain' => $o->domain, 'product_code' => $o->product_code,
            'quantity' => $o->quantity, 'price_shown' => $o->price_shown, 'note' => $o->note,
            'message' => $o->message, 'checkout_url' => $o->checkout_url,
            'created_at' => $o->created_at?->toIso8601String(), 'updated_at' => $o->updated_at?->toIso8601String(),
        ];
    }
}
