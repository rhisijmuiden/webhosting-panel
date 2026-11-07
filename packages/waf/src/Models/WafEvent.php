<?php

declare(strict_types=1);

namespace Rhis\Waf\Models;

use App\Models\Website;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One request that matched firewall rules.
 *
 * @property list<array{id: int, message: string, data: string, severity: ?int, tags: list<string>}> $rules
 */
class WafEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rules' => 'array', 'blocked' => 'boolean', 'occurred_at' => 'datetime'];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function scopeFilter(Builder $q, array $f): Builder
    {
        return $q
            ->when(($f['type'] ?? '') === 'blocked', fn ($q) => $q->where('blocked', true))
            ->when(($f['type'] ?? '') === 'detected', fn ($q) => $q->where('blocked', false))
            ->when(! empty($f['rule']) && ctype_digit((string) $f['rule']), fn ($q) => $q->where('rule_ids', 'like', '%,'.(int) $f['rule'].',%'))
            ->when(! empty($f['ip']), fn ($q) => $q->where('ip', (string) $f['ip']))
            ->when(! empty($f['site']) && ctype_digit((string) $f['site']), fn ($q) => $q->where('website_id', (int) $f['site']));
    }

    /** Path of the request without the query string, for a "this page only" exception. */
    public function path(): string
    {
        $path = (string) parse_url((string) $this->uri, PHP_URL_PATH);

        return $path !== '' && $path[0] === '/' ? $path : '/';
    }

    /** The most telling rule: highest severity (lowest number) first. */
    public function mainRule(): ?array
    {
        return collect($this->rules)->sortBy(fn ($r) => $r['severity'] ?? 9)->first();
    }

    /** Attack kind from the CRS tags (attack-sqli -> sqli). */
    public function kind(): string
    {
        $tags = collect($this->rules)->flatMap(fn ($r) => $r['tags'] ?? [])->map(fn ($t) => substr($t, 7))->filter();

        return (string) ($tags->countBy()->sortDesc()->keys()->first() ?? 'generic');
    }
}
