<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Client;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\CrmApi\Models\Order;
use Rhis\CrmApi\Services\Catalog;
use Rhis\CrmApi\Services\Orders;
use Rhis\CrmApi\Support\ApiError;

/** Customers order extra services (Microsoft 365, a bigger plan, ...) at webshop prices. */
final class ShopController extends Controller
{
    public function index(Request $request, Catalog $catalog): View
    {
        $customer = $request->user()->customer;

        return view('crm-api::client.shop', [
            'products' => $catalog->products(),
            'shopUrl' => $catalog->shopUrl(),
            'domains' => $customer->websites()->orderBy('domain')->pluck('domain'),
            'orders' => Order::where('customer_id', $customer->id)->latest()->limit(20)->get(),
            'selected' => $request->query('product'),
        ]);
    }

    public function store(Request $request, Orders $orders): RedirectResponse
    {
        $customer = $request->user()->customer;
        $data = $request->validate([
            'product' => ['required', 'string', 'max:64'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'domain' => ['nullable', 'string', 'max:253'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if (! empty($data['domain']) && ! $customer->websites()->where('domain', $data['domain'])->exists()) {
            return back()->withErrors(['domain' => __('Choose one of your own domains.')]);
        }
        try {
            $orders->request($customer, $data['product'], (int) ($data['quantity'] ?? 1), $data['domain'] ?? null, $data['note'] ?? null);
        } catch (ApiError) {
            return back()->withErrors(['product' => __('This product is not available.')]);
        }

        return redirect()->route('client.shop.index')->with('status', __('Thank you, your order has been sent. You will hear from us soon.'));
    }

    public function cancel(Request $request, Order $order, Orders $orders): RedirectResponse
    {
        abort_unless($order->customer_id === $request->user()->customer_id, 404);
        try {
            $orders->cancel($order);
        } catch (ApiError $e) {
            return back()->withErrors(['order' => $e->getMessage()]);
        }

        return back()->with('status', __('Order cancelled.'));
    }
}
