<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    use HasUlids;

    protected $table = 'crm_webhooks';

    protected $fillable = ['url', 'events', 'secret', 'description'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['events' => 'array', 'secret' => 'encrypted'];
    }

    public function lastDelivery(): ?WebhookDelivery
    {
        return WebhookDelivery::where('webhook_id', $this->id)->latest('created_at')->latest('id')->first();
    }
}
