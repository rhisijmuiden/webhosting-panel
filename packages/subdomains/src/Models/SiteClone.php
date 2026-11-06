<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Models;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One copy of a website into another website of the same customer. */
final class SiteClone extends Model
{
    protected $table = 'site_clones';

    protected $fillable = [
        'customer_id', 'source_website_id', 'target_website_id', 'source_domain', 'target_domain', 'status',
        'job_id', 'replace', 'noindex', 'result', 'error', 'user_id', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['replace' => 'boolean', 'noindex' => 'boolean', 'result' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'source_website_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'target_website_id');
    }

    public function busy(): bool
    {
        return in_array($this->status, ['waiting', 'running'], true);
    }
}
