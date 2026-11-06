<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasUlids;

    protected $table = 'crm_webhook_deliveries';

    protected $fillable = ['webhook_id', 'event', 'payload', 'status', 'attempts', 'http_status', 'last_error', 'delivered_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'delivered_at' => 'datetime'];
    }
}
