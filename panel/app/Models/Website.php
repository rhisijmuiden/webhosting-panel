<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Website extends Model
{
    use HasFactory;

    public const PHP_VERSIONS = ['8.3', '8.4', '8.5'];

    public const PROFILES = ['php', 'wordpress', 'laravel'];

    protected $fillable = [
        'customer_id', 'domain', 'aliases', 'profile', 'php_version', 'page_cache', 'ssl_status',
        'status', 'system_user', 'document_root', 'disk_used_mb', 'disk_quota_mb', 'last_error', 'php_settings',
    ];

    protected function casts(): array
    {
        return ['aliases' => 'array', 'page_cache' => 'boolean', 'php_settings' => 'array'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function traffic(): HasMany
    {
        return $this->hasMany(WebsiteTraffic::class);
    }

    public function trafficBytes(?string $month = null): int
    {
        return (int) $this->traffic()->where('month', $month ?? now()->utc()->format('Y-m'))->value('bytes');
    }

    public function databases(): HasMany
    {
        return $this->hasMany(SiteDatabase::class)->orderBy('name');
    }

    /** Apply the JSON that whp-site returns for a site. */
    public function fillFromAgent(array $site): void
    {
        $this->fill(array_filter([
            'aliases' => $site['aliases'] ?? null,
            'php_version' => $site['php'] ?? null,
            'page_cache' => isset($site['cache']) ? (bool) $site['cache'] : null,
            'profile' => $site['profile'] ?? null,
            'ssl_status' => $site['ssl'] ?? null,
            'system_user' => $site['user'] ?? null,
            'document_root' => $site['docroot'] ?? null,
            'disk_used_mb' => isset($site['disk']['used_mb']) ? (int) $site['disk']['used_mb'] : null,
            'disk_quota_mb' => isset($site['disk']['quota_mb']) ? (int) $site['disk']['quota_mb'] : null,
        ], fn ($v) => $v !== null));
    }
}
