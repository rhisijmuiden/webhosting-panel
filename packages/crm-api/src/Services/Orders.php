<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use Rhis\CrmApi\Models\Order;
use Rhis\CrmApi\Models\Subscription;
use Rhis\CrmApi\Support\ApiError;
use Rhis\CrmApi\Support\Present;

/**
 * Orders customers place in the panel (for example Microsoft 365 from the
 * mail wizard). Other packages call request(); the CRM gets order.requested.
 */
final class Orders
{
    public function __construct(private readonly Catalog $catalog, private readonly Webhooks $webhooks) {}

    public function request(Customer $customer, string $productCode, int $quantity = 1, ?string $domain = null, ?string $note = null): Order
    {
        $product = $this->catalog->product($productCode) ?? throw ApiError::notFound('Product');
        $quantity = ($product['per_unit'] ?? false) ? max(1, min(1000, $quantity)) : 1;
        $subscription = $domain ? Subscription::where('customer_id', $customer->id)->where('primary_domain', $domain)->first() : null;

        $order = Order::create([
            'status' => 'requested', 'customer_id' => $customer->id, 'subscription_id' => $subscription?->id,
            'domain' => $domain, 'product_code' => $productCode, 'quantity' => $quantity,
            'price_shown' => $product['price'], 'note' => $note,
        ]);
        ActivityLog::record('crm.order_requested', $productCode, ['quantity' => $quantity, 'domain' => $domain], $customer->id);
        $this->webhooks->dispatch('order.requested', ['order' => Present::order($order->fresh('customer'))]);

        return $order;
    }

    public function cancel(Order $order): Order
    {
        if (! in_array($order->status, ['requested', 'awaiting_payment'], true)) {
            throw ApiError::conflict('This order can no longer be cancelled');
        }
        $order->update(['status' => 'cancelled']);
        $this->webhooks->dispatch('order.cancelled', ['order' => Present::order($order->fresh('customer'))]);

        return $order;
    }
}
