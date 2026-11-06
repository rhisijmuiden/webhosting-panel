<?php

declare(strict_types=1);

namespace Rhis\Reports\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Rhis\Reports\Options;

/** Who receives the monthly report of a customer. No row = the default from the settings. */
class Preference extends Model
{
    protected $table = 'report_preferences';

    protected $fillable = ['customer_id', 'enabled', 'extra_emails', 'changed_by'];

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
        $p = self::firstOrNew(['customer_id' => $customer->id], ['enabled' => app(Options::class)->defaultOn(), 'extra_emails' => []]);

        return $p->setRelation('customer', $customer);
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
