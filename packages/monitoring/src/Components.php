<?php

declare(strict_types=1);

namespace Rhis\Monitoring;

use Rhis\Monitoring\Models\Check;
use Rhis\Monitoring\Models\Incident;
use Rhis\Monitoring\Models\Notice;

/** The parts of the platform on the status page (Websites, Email, Databases, Control panel). */
final class Components
{
    /** @return array<string, string> key => label (translated) */
    public static function all(): array
    {
        return collect((array) config('monitoring.components'))->map(fn ($c) => __($c['label']))->all();
    }

    /** The component a systemd unit belongs to. */
    public static function of(string $unit): ?string
    {
        foreach ((array) config('monitoring.components') as $key => $c) {
            foreach ($c['services'] as $pattern) {
                if (fnmatch($pattern, $unit)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function units(string $component): array
    {
        return Check::where('kind', 'service')->get()
            ->filter(fn (Check $c) => self::of((string) ($c->value['unit'] ?? '')) === $component)
            ->map(fn (Check $c) => $c->key)->values()->all();
    }

    /**
     * Status of every component now, with the worst notice on top of the measured state.
     *
     * @return array<string, array{label: string, status: string, uptime: ?float, days: array}>
     */
    public static function overview(int $days = 90): array
    {
        $checks = Check::where('kind', 'component')->get()->keyBy('label');
        $notices = Notice::visible()->get()->reject(fn (Notice $n) => $n->isUpcoming());
        $out = [];
        foreach (self::all() as $key => $label) {
            $status = $checks->get($key)?->status ?? 'ok';
            // Planned maintenance is shown as such, it does not count as a problem.
            foreach ($notices as $n) {
                if (in_array($key, $n->components ?? [], true)) {
                    $status = Severity::worst([$status, match ($n->level) { 'outage' => 'critical', 'degraded' => 'warning', default => 'ok' }]);
                }
            }
            $history = Uptime::days("component:$key", $days);
            $out[$key] = ['label' => $label, 'status' => $status, 'uptime' => Uptime::percent($history), 'days' => $history,
                'maintenance' => $notices->contains(fn (Notice $n) => $n->level === 'maintenance' && in_array($key, $n->components ?? [], true))];
        }

        return $out;
    }

    /** Service incidents of the last days, for the public history (never anything about one customer). */
    public static function incidents(int $days = 14)
    {
        return Incident::where('kind', 'service')->where('started_at', '>=', now()->subDays($days))
            ->latest('started_at')->limit(30)->get()
            ->map(function (Incident $i) {
                $unit = Check::where('key', $i->key)->first()?->value['unit'] ?? substr($i->key, 8);
                $i->setAttribute('component', self::of((string) $unit));

                return $i;
            })->filter(fn (Incident $i) => $i->component !== null)->values();
    }
}
