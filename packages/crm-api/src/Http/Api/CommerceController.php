<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Rhis\CrmApi\Models\Order;
use Rhis\CrmApi\Services\Catalog;
use Rhis\CrmApi\Support\ApiError;
use Rhis\CrmApi\Support\Page;
use Rhis\CrmApi\Support\Present;

/** Catalog (webshop prices) and orders placed in the panel. */
final class CommerceController
{
    public function catalog(Catalog $catalog): JsonResponse
    {
        return response()->json($catalog->get());
    }

    public function putCatalog(Request $request, Catalog $catalog): JsonResponse
    {
        $data = $request->validate([
            'shop_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'products' => ['present', 'array', 'max:500'],
            'products.*.code' => ['required', 'distinct', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            'products.*.kind' => ['required', Rule::in(['m365', 'plan_upgrade', 'domain', 'ssl', 'addon', 'other'])],
            'products.*.name' => ['required', 'array'],
            'products.*.name.en' => ['required', 'string', 'max:150'],
            'products.*.name.nl' => ['sometimes', 'string', 'max:150'],
            'products.*.description' => ['sometimes', 'array'],
            'products.*.description.en' => ['sometimes', 'string', 'max:1000'],
            'products.*.description.nl' => ['sometimes', 'string', 'max:1000'],
            'products.*.price' => ['required', 'array'],
            'products.*.price.amount' => ['required', 'regex:/^[0-9]+\.[0-9]{2}$/'],
            'products.*.price.currency' => ['sometimes', Rule::in(['EUR'])],
            'products.*.price.vat_rate' => ['sometimes', 'regex:/^[0-9]{1,2}(\.[0-9]{1,2})?$/'],
            'products.*.price.interval' => ['sometimes', Rule::in(['once', 'month', 'year'])],
            'products.*.plan_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'products.*.per_unit' => ['sometimes', 'boolean'],
            'products.*.shop_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'products.*.active' => ['sometimes', 'boolean'],
        ]);
        foreach ($data['products'] as &$p) {
            $p['price']['currency'] ??= 'EUR';
            $p['active'] ??= true;
            $p['per_unit'] ??= false;
        }

        return response()->json($catalog->put($data));
    }

    public function orders(Request $request): JsonResponse
    {
        $q = Order::with('customer');
        if (is_string($status = $request->query('status'))) {
            $q->where('status', $status);
        }

        return response()->json(Page::of($q, $request, fn ($o) => Present::order($o)));
    }

    public function order(string $id): JsonResponse
    {
        return response()->json(Present::order(Order::with('customer')->find($id) ?? throw ApiError::notFound('Order')));
    }

    public function updateOrder(Request $request, string $id): JsonResponse
    {
        $order = Order::find($id) ?? throw ApiError::notFound('Order');
        $data = $request->validate([
            'status' => ['required', Rule::in(Order::STATUSES)],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'checkout_url' => ['sometimes', 'nullable', 'url:https', 'max:2000'],
        ]);
        $order->update($data);

        return response()->json(Present::order($order->fresh('customer')));
    }
}
