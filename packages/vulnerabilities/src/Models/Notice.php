<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A security mail that was sent to a customer. */
class Notice extends Model
{
    protected $table = 'security_notices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'finding_ids' => 'array', 'sent_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
