<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The period shown on the Statistics page: a preset (24h ... 2y) or a custom
 * from/to (also what drag-to-zoom in a graph sets). It picks the stored
 * resolution that still has data for that period and a bucket size that keeps
 * a graph at a few hundred points.
 */
final class StatsRange
{
    public const PRESETS = ['24h' => 24, '7d' => 168, '30d' => 720, '90d' => 2160, '1y' => 8760, '2y' => 17520];

    private const MAX_POINTS = 500;

    private function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function fromRequest(Request $request, ?CarbonImmutable $now = null): self
    {
        $now ??= CarbonImmutable::now();
        $key = (string) $request->query('range', '24h');
        if ($key === 'custom') {
            try {
                $from = CarbonImmutable::parse((string) $request->query('from'));
                $to = $request->filled('to') ? CarbonImmutable::parse((string) $request->query('to')) : $now;
            } catch (\Throwable) {
                return new self('24h', $now->subHours(24), $now);
            }
            if ($from > $to) {
                [$from, $to] = [$to, $from];
            }
            $to = $to->min($now);
            // At least ten minutes, at most five years.
            $from = $from->min($to->subMinutes(10))->max($to->subYears(5));

            return new self('custom', $from, $to);
        }
        $key = array_key_exists($key, self::PRESETS) ? $key : '24h';

        return new self($key, $now->subHours(self::PRESETS[$key]), $now);
    }

    public function seconds(): int
    {
        return max(60, $this->to->getTimestamp() - $this->from->getTimestamp());
    }

    /** Resolution of metric_samples / website_metric_samples: minute (48h), hour (90d) or day. */
    public function metricsResolution(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        if ($this->from >= $now->subHours(47) && $this->seconds() <= 48 * 3600) {
            return 'minute';
        }
        if ($this->from >= $now->subDays(90)->subMinutes(5) && $this->seconds() <= 90 * 86400 + 300) {
            return 'hour';
        }

        return 'day';
    }

    /** Resolution of visit_stats: hour (400 days, up to 31 days wide) or day. */
    public function visitsResolution(?CarbonImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();

        return $this->from >= $now->subDays(399) && $this->seconds() <= 31 * 86400 ? 'hour' : 'day';
    }

    /** Bucket size in seconds for a resolution, so a graph stays around MAX_POINTS points. */
    public function bucket(string $resolution): int
    {
        $step = ['minute' => 60, 'hour' => 3600, 'day' => 86400][$resolution];
        $wanted = (int) ceil($this->seconds() / self::MAX_POINTS);

        return max($step, (int) ceil($wanted / $step) * $step);
    }

    /** Query string to keep this range in links. */
    public function query(): array
    {
        return $this->key === 'custom'
            ? ['range' => 'custom', 'from' => $this->from->toIso8601String(), 'to' => $this->to->toIso8601String()]
            : ['range' => $this->key];
    }
}
