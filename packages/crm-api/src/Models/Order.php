<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use HasUlids;

    public const STATUSES = ['requested', 'awaiting_payment', 'accepted', 'completed', 'rejected', 'cancelled'];

    protected $table = 'crm_orders';

    protected $fillable = [
        'external_id', 'status', 'customer_id', 'subscription_id', 'domain', 'product_code', 'quantity',
        'price_shown', 'note', 'message', 'checkout_url',
    ];

    protected function casts(): array
    {
        return ['price_shown' => 'array', 'quantity' => 'integer'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
