<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's choice to get a mail when one of their websites goes down. */
class Contact extends Model
{
    protected $table = 'monitor_contacts';

    protected $fillable = ['customer_id', 'enabled', 'extra_emails'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'extra_emails' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public static function for(Customer $customer): self
    {
        return self::firstOrNew(['customer_id' => $customer->id], ['enabled' => false, 'extra_emails' => []]);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        if (! $this->enabled) {
            return [];
        }

        return array_values(array_unique(array_filter([$this->customer?->email, ...($this->extra_emails ?? [])],
            fn ($m) => is_string($m) && filter_var($m, FILTER_VALIDATE_EMAIL))));
    }
}
