<?php

namespace App\Services;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Visitors, page views, requests and data traffic per website and for the whole
 * server, per hour and per day. The agent counts them from the Nginx traffic log
 * (traffic.history, see agent/whp_agent/visits.py); this stores them for the
 * Statistics page. Hours are kept 400 days, days forever.
 */
class Visits
{
    public const FIELDS = ['visitors', 'pageviews', 'requests', 'bots', 'bytes'];

    public const KEEP_HOURS_DAYS = 400;

    public function __construct(private readonly AgentClient $agent) {}

    public function collect(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        // The agent keeps 72 hours; ask for the last few so late log lines are picked up.
        $since = $now->subHours(3)->startOfHour();
        $data = $this->agent->call('traffic.history', ['since' => $since->getTimestamp()], 120);

        $sites = Website::pluck('id', 'domain');
        $rows = 0;
        foreach (['hour' => $data['hours'] ?? [], 'day' => $data['days'] ?? []] as $resolution => $buckets) {
            foreach ($buckets as $bucket) {
                $start = CarbonImmutable::createFromTimestamp((int) $bucket['start'], config('app.timezone'));
                foreach ($bucket['sites'] ?? [] as $name => $counters) {
                    $websiteId = $name === '*' ? null : ($sites[$name] ?? null);
                    if ($name !== '*' && $websiteId === null) {
                        continue; // not a website of this panel (an alias, the default server, ...)
                    }
                    DB::table('visit_stats')->updateOrInsert(
                        ['website_id' => $websiteId, 'resolution' => $resolution, 'period_start' => $start],
                        collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => (int) ($counters[$f] ?? 0)])->all(),
                    );
                    $rows++;
                }
            }
        }

        DB::table('visit_stats')->where('resolution', 'hour')->where('period_start', '<', $now->subDays(self::KEEP_HOURS_DAYS))->delete();

        return $rows;
    }
}
