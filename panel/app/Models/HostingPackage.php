<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Number;

/**
 * A hosting package. Every limit is NULL for "unlimited" (the default for new packages).
 * A package is assigned per website through its subscription, so every limit
 * applies to one website (like a Plesk service plan).
 */
class HostingPackage extends Model
{
    use HasFactory;

    /**
     * What one subscription may create or use. key => [label, unit, form min, form max].
     * (max_websites is kept for the CRM API only: one subscription is one website.)
     */
    public const USAGE_LIMITS = [
        'disk_mb' => ['Disk space', 'MB', 100, 10_000_000],
        'traffic_gb_month' => ['Data traffic per month', 'GB', 1, 1_000_000],
        'max_mailboxes' => ['Mailboxes', null, 0, 100_000],
        'max_databases' => ['Databases', null, 0, 10_000],
        'max_cronjobs' => ['Cron jobs', null, 0, 1_000],
    ];

    /**
     * Resource jail: the most one subscription (its website, cron jobs and SSH)
     * may use, so one website can never take down the server.
     */
    public const RESOURCE_LIMITS = [
        'cpu_percent' => ['CPU', '%', 10, 10_000],
        'memory_mb' => ['Memory (RAM)', 'MB', 64, 10_000_000],
        'io_mbps' => ['Disk speed', 'MB/s', 1, 100_000],
        'max_processes' => ['Processes', null, 16, 100_000],
        'php_workers' => ['PHP workers per website', null, 1, 999],
        'db_connections' => ['Database connections per website', null, 1, 10_000],
    ];

    public const TRAFFIC_ACTIONS = ['notify', 'suspend'];

    protected $fillable = [
        'name', 'crm_id', 'description', 'disk_mb', 'traffic_gb_month', 'traffic_action', 'max_websites',
        'max_mailboxes', 'max_databases', 'max_cronjobs', 'cpu_percent', 'memory_mb', 'io_mbps',
        'max_processes', 'php_workers', 'db_connections', 'allow_ssh', 'is_active',
    ];

    protected $attributes = ['traffic_action' => 'notify'];

    protected function casts(): array
    {
        return ['allow_ssh' => 'boolean', 'is_active' => 'boolean'];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public static function limitKeys(): array
    {
        return array_keys(self::USAGE_LIMITS + self::RESOURCE_LIMITS);
    }

    public function isUnlimited(string $key): bool
    {
        return $this->{$key} === null;
    }

    /** Whether the resource jail has at least one cap (otherwise only fair sharing applies). */
    public function hasResourceCaps(): bool
    {
        return collect(['cpu_percent', 'memory_mb', 'io_mbps', 'max_processes'])->contains(fn ($k) => $this->{$k} !== null);
    }

    /** A limit for people: "Unlimited", "10 GB", "200 % (2 cores)". */
    public function limitLabel(string $key): string
    {
        $value = $this->{$key};
        if ($value === null) {
            return __('Unlimited');
        }

        return match ($key) {
            'disk_mb', 'memory_mb' => Number::fileSize($value * 1024 * 1024),
            'traffic_gb_month' => Number::fileSize($value * 1024 ** 3).' / '.__('month'),
            'cpu_percent' => trans_choice(':percent % (:cores core)|:percent % (:cores cores)', $value / 100, ['percent' => $value, 'cores' => rtrim(rtrim(number_format($value / 100, 1), '0'), '.')]),
            'io_mbps' => $value.' MB/s',
            default => (string) $value,
        };
    }
}
