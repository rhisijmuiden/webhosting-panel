<?php

declare(strict_types=1);

namespace Rhis\Backups;

use App\Models\Customer;
use App\Models\SiteDatabase;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Turns the snapshot list of one backup location into a browser: per day
 * which restore points exist, and on a day what belongs to which customer.
 */
final class RestorePointBrowser
{
    /** @param array<int, array> $snapshots as returned by backup.list */
    public function __construct(private readonly array $snapshots, private readonly string $timezone) {}

    /** [{date, count, full, scopes}], newest day first. Pre-restore snapshots are left out. */
    public function days(): array
    {
        return $this->points()->groupBy('date')->map(fn (Collection $day, string $date) => [
            'date' => $date,
            'count' => $day->pluck('scope')->unique()->count(),
            'full' => $day->contains('kind', 'full'),
        ])->sortKeysDesc()->values()->all();
    }

    /**
     * The newest restore point of every item on $date, grouped:
     * [['key' => 'server'|'customer-<id>'|'unknown', 'title' => ..., 'customer' => ?Customer, 'items' => [...]]].
     */
    public function groups(string $date): array
    {
        $points = $this->points()->where('date', $date)->sortByDesc('time')->unique('scope')->values();

        $domains = $points->map(fn ($p) => $p['domain'])->filter()->unique()->values();
        $dbNames = $points->map(fn ($p) => $p['database'])->filter()->unique()->values();
        $websites = Website::with('customer')->whereIn('domain', $domains)->get()->keyBy('domain');
        $databases = $dbNames->isEmpty() || ! class_exists(SiteDatabase::class) ? collect()
            : SiteDatabase::with('website.customer')->whereIn('name', $dbNames)->get()->keyBy('name');

        $groups = [];
        foreach ($points as $p) {
            $website = $p['domain'] ? $websites->get($p['domain']) : ($p['database'] ? $databases->get($p['database'])?->website : null);
            $customer = $website?->customer;
            $key = $p['scope'] === 'system' ? 'server' : ($customer ? 'customer-'.$customer->id : 'unknown');
            $groups[$key] ??= [
                'key' => $key,
                'title' => match (true) {
                    $key === 'server' => __('Server'),
                    $customer !== null => $customer->displayName(),
                    default => __('Not linked to a customer'),
                },
                'customer' => $customer,
                'items' => [],
            ];
            $groups[$key]['items'][] = $p + ['website' => $website?->domain];
        }
        foreach ($groups as &$g) {
            usort($g['items'], fn ($a, $b) => [$a['order'], $a['label']] <=> [$b['order'], $b['label']]);
        }
        unset($g);
        uasort($groups, fn ($a, $b) => [$a['key'] === 'server' ? 0 : ($a['key'] === 'unknown' ? 2 : 1), $a['title']]
            <=> [$b['key'] === 'server' ? 0 : ($b['key'] === 'unknown' ? 2 : 1), $b['title']]);

        return array_values($groups);
    }

    private function points(): Collection
    {
        return collect($this->snapshots)
            ->filter(fn ($s) => ($s['kind'] ?? '') !== 'pre-restore' && is_string($s['scope'] ?? null) && $s['scope'] !== 'unknown')
            ->map(function ($s) {
                $time = Carbon::parse($s['time'])->setTimezone($this->timezone);
                [$type, $name] = array_pad(explode(':', $s['scope'], 2), 2, null);

                return [
                    'id' => $s['id'], 'short_id' => $s['short_id'] ?? substr($s['id'], 0, 8), 'scope' => $s['scope'],
                    'kind' => $s['kind'] ?? 'unknown', 'time' => $time, 'date' => $time->toDateString(),
                    'domain' => in_array($type, ['site', 'mail'], true) ? $name : null,
                    'database' => $type === 'db' ? $name : null,
                    'order' => ['system' => 0, 'site' => 1, 'db' => 2, 'mail' => 3][$type] ?? 9,
                    'label' => match ($type) {
                        'system' => __('Server configuration'),
                        'site' => __('Website').' '.$name,
                        'mail' => __('Mail').' '.$name,
                        'db' => __('Database').' '.$name,
                        default => $s['scope'],
                    },
                ];
            });
    }
}
