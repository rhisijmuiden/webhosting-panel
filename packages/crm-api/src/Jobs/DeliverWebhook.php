<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Rhis\CrmApi\Models\Webhook;
use Rhis\CrmApi\Models\WebhookDelivery;
use Rhis\CrmApi\Services\Webhooks;
use Throwable;

final class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);
        $hook = $delivery ? Webhook::find($delivery->webhook_id) : null;
        if (! $delivery || ! $hook || $delivery->status === 'delivered') {
            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $status = null;
        $error = null;
        try {
            $response = Http::timeout((int) config('crm-api.webhook_timeout', 10))
                ->withoutRedirecting()
                ->withHeaders([
                    'X-WHP-Event' => $delivery->event,
                    'X-WHP-Delivery' => $delivery->id,
                    'X-WHP-Signature' => Webhooks::signature($hook->secret, $body, time()),
                    'User-Agent' => 'WebhostingPanel-Webhooks/1.0',
                ])
                ->withBody($body, 'application/json')
                ->post($hook->url);
            $status = $response->status();
            $ok = $response->successful();
            $error = $ok ? null : 'HTTP '.$status;
        } catch (Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }

        $attempts = $delivery->attempts + 1;
        $retries = (array) config('crm-api.webhook_retries', []);
        if ($ok) {
            $delivery->update(['status' => 'delivered', 'attempts' => $attempts, 'http_status' => $status, 'delivered_at' => now(), 'last_error' => null]);

            return;
        }
        $retry = $retries[$attempts - 1] ?? null;
        $delivery->update([
            'status' => $retry !== null ? 'retrying' : 'failed',
            'attempts' => $attempts, 'http_status' => $status, 'last_error' => mb_substr((string) $error, 0, 1000),
        ]);
        if ($retry !== null) {
            self::dispatch($this->deliveryId)->delay(now()->addSeconds((int) $retry));
        }
    }
}
