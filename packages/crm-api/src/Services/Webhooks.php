<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Services;

use Illuminate\Support\Str;
use Rhis\CrmApi\Jobs\DeliverWebhook;
use Rhis\CrmApi\Models\Webhook;
use Rhis\CrmApi\Models\WebhookDelivery;

final class Webhooks
{
    public const EVENTS = [
        'ping', 'subscription.provisioned', 'subscription.failed', 'subscription.changed', 'subscription.suspended',
        'subscription.unsuspended', 'subscription.terminated', 'usage.limit_reached', 'backup.failed',
        'customer.updated', 'order.requested', 'order.cancelled',
        'domain_alias.added', 'domain_alias.removed', 'domain_alias.converted',
    ];

    /** Queue the event for every endpoint that subscribed to it. */
    public function dispatch(string $event, array $data, ?Webhook $only = null, bool $queue = true): array
    {
        $hooks = $only ? collect([$only]) : Webhook::all()->filter(fn (Webhook $w) => in_array($event, $w->events, true));
        $deliveries = [];
        foreach ($hooks as $hook) {
            $delivery = new WebhookDelivery(['webhook_id' => $hook->id, 'event' => $event, 'status' => 'pending']);
            $delivery->id = (string) Str::ulid();
            $delivery->payload = ['id' => $delivery->id, 'type' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data];
            $delivery->save();
            if ($queue) {
                DeliverWebhook::dispatch($delivery->id);
            }
            $deliveries[] = $delivery;
        }

        return $deliveries;
    }

    /** `t=<unix>,v1=<hex hmac>` over "<t>.<body>". */
    public static function signature(string $secret, string $body, int $time): string
    {
        return 't='.$time.',v1='.hash_hmac('sha256', $time.'.'.$body, $secret);
    }

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }
}
