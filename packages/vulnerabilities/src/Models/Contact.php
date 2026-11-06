<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who receives the security mails of a customer. No row = the customer's own address, on. */
class Contact extends Model
{
    protected $table = 'security_contacts';

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
        return self::firstOrNew(['customer_id' => $customer->id], ['enabled' => true, 'extra_emails' => []]);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        if (! $this->enabled) {
            return [];
        }

        return collect([$this->customer->email, ...($this->extra_emails ?? [])])
            ->filter(fn ($e) => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL))
            ->map(fn ($e) => strtolower($e))->unique()->values()->all();
    }
}
