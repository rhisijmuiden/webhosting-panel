<?php

declare(strict_types=1);

namespace Rhis\Reports\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A monthly report that was sent to a customer (or failed). */
class Send extends Model
{
    protected $table = 'report_sends';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'summary' => 'array', 'sent_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
