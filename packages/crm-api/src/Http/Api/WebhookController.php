<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Rhis\CrmApi\Jobs\DeliverWebhook;
use Rhis\CrmApi\Models\Webhook;
use Rhis\CrmApi\Models\WebhookDelivery;
use Rhis\CrmApi\Services\Webhooks;
use Rhis\CrmApi\Support\ApiError;
use Rhis\CrmApi\Support\Present;

final class WebhookController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Webhook::orderBy('id')->get()->map(fn ($w) => Present::webhook($w))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url:https', 'max:2000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::in(Webhooks::EVENTS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);
        $secret = Webhooks::newSecret();
        $hook = Webhook::create(['url' => $data['url'], 'events' => array_values(array_unique($data['events'])), 'secret' => $secret, 'description' => $data['description'] ?? null]);

        return response()->json(Present::webhook($hook) + ['secret' => $secret], 201);
    }

    public function destroy(string $id): JsonResponse
    {
        $hook = Webhook::find($id) ?? throw ApiError::notFound('Webhook');
        WebhookDelivery::where('webhook_id', $hook->id)->delete();
        $hook->delete();

        return response()->json(null, 204);
    }

    public function test(string $id, Webhooks $webhooks): JsonResponse
    {
        $hook = Webhook::find($id) ?? throw ApiError::notFound('Webhook');
        $delivery = $webhooks->dispatch('ping', ['message' => 'Webhook test from the panel'], $hook, queue: false)[0];
        // Deliver now, so the answer shows the result.
        (new DeliverWebhook($delivery->id))->handle();

        return response()->json(Present::delivery($delivery->fresh()));
    }
}
