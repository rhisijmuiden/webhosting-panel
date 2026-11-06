<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Models;

use Illuminate\Database\Eloquent\Model;

/** Where alerts go: mail addresses or a Microsoft Teams channel. */
class Channel extends Model
{
    public const TYPES = ['mail', 'teams'];

    protected $table = 'monitor_channels';

    protected $fillable = ['type', 'name', 'target', 'min_severity', 'locale', 'recoveries', 'enabled', 'last_sent_at', 'last_error'];

    protected $hidden = ['target'];

    protected function casts(): array
    {
        // Teams webhook URLs are secrets: anyone with the URL can post in the channel.
        return ['target' => 'encrypted', 'recoveries' => 'boolean', 'enabled' => 'boolean', 'last_sent_at' => 'datetime'];
    }

    /** @return list<string> */
    public function addresses(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $this->target) ?: []),
            fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL)));
    }

    public function wants(string $severity): bool
    {
        return $this->min_severity !== 'critical' || $severity === 'critical';
    }

    /** The webhook host, for the screens (never the whole URL). */
    public function maskedTarget(): string
    {
        if ($this->type === 'mail') {
            return $this->target ?: __('All administrators');
        }
        $host = parse_url((string) $this->target, PHP_URL_HOST);

        return $host ? $host.'/…' : '–';
    }
}
