<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Website extends Model
{
    use HasFactory;

    public const PHP_VERSIONS = ['8.3', '8.4', '8.5'];

    public const PROFILES = ['php', 'wordpress', 'laravel'];

    protected $fillable = [
        'customer_id', 'parent_id', 'domain', 'aliases', 'profile', 'php_version', 'page_cache', 'ssl_status',
        'status', 'system_user', 'document_root', 'disk_used_mb', 'disk_quota_mb', 'last_error', 'php_settings',
    ];

    /** Package for the subscription that is created together with a new website (not stored here). */
    public ?int $initialPackageId = null;

    protected function casts(): array
    {
        return ['aliases' => 'array', 'page_cache' => 'boolean', 'php_settings' => 'array'];
    }

    protected static function booted(): void
    {
        // Every website is a subscription: one website on one hosting package.
        static::created(function (Website $website) {
            // A subdomain (parent_id) belongs to the subscription of its main website.
            if ($website->getAttribute('parent_id')) {
                return;
            }
            Subscription::firstOrCreate(['website_id' => $website->id], [
                'hosting_package_id' => $website->initialPackageId,
                'status' => $website->status === 'suspended' ? 'suspended' : 'active',
            ]);
        });
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** The subscription whose package and resource jail apply: its own, or the main website's for a subdomain. */
    public function effectiveSubscription(): ?Subscription
    {
        $parent = $this->getAttribute('parent_id');
        if ($parent) {
            return Subscription::with('package')->where('website_id', $parent)->first();
        }

        return $this->subscription;
    }

    /** The hosting package of this website's subscription. */
    public function getPackageAttribute(): ?HostingPackage
    {
        return $this->effectiveSubscription()?->package;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The main website of a subdomain (null for a main website). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Website::class, 'parent_id');
    }

    /** Subdomains (test.example.com) of this website: part of its subscription. */
    public function subdomains(): HasMany
    {
        return $this->hasMany(Website::class, 'parent_id')->orderBy('domain');
    }

    public function isSubdomain(): bool
    {
        return $this->parent_id !== null;
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
