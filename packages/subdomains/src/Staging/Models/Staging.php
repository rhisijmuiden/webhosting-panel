<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Staging\Models;

use App\Models\Customer;
use App\Models\Website;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Rhis\Subdomains\Models\SiteClone;

/** A WordPress staging copy of a live website: work on it, then push it to live. */
final class Staging extends Model
{
    protected $table = 'wp_stagings';

    protected $fillable = [
        'customer_id', 'live_website_id', 'staging_website_id', 'live_domain', 'staging_domain', 'path', 'clone_id',
        'status', 'error', 'user_id', 'copied_at', 'last_push_at',
    ];

    protected function casts(): array
    {
        return ['copied_at' => 'datetime', 'last_push_at' => 'datetime'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function live(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'live_website_id');
    }

    public function staging(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'staging_website_id');
    }

    public function siteClone(): BelongsTo
    {
        return $this->belongsTo(SiteClone::class, 'clone_id');
    }

    public function pushes(): HasMany
    {
        return $this->hasMany(StagingPush::class)->latest('id');
    }

    public function runningPush(): ?StagingPush
    {
        return $this->pushes()->where('status', 'running')->first();
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function busy(): bool
    {
        return $this->status === 'copying' || $this->runningPush() !== null;
    }

    public function liveUrl(): string
    {
        return $this->url($this->live, $this->live_domain);
    }

    public function stagingUrl(): string
    {
        return $this->url($this->staging, $this->staging_domain);
    }

    private function url(?Website $site, string $domain): string
    {
        $scheme = $site?->ssl_status === 'active' ? 'https' : 'http';

        return $scheme.'://'.$domain.($this->path !== '' ? '/'.$this->path : '');
    }
}
