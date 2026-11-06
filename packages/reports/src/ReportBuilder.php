<?php

declare(strict_types=1);

namespace Rhis\Reports;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Collects the numbers of one customer for one calendar month: visitors, uptime,
 * backups, security, mail, updates and usage. Every part is optional: when a
 * package or its data is missing, that part is null and the mail leaves it out.
 * One builder is used for a whole run, so the backup snapshot lists are fetched once.
 */
final class ReportBuilder
{
    /** @var array<string, list<array>>|null scope => snapshots, from the server backup locations */
    private ?array $snapshots = null;

    private array $tables = [];

    public function __construct(private readonly AgentClient $agent) {}

    /** The month the scheduled report is about: the month before $now. */
    public static function previousMonth(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now(config('app.timezone')))->startOfMonth()->subMonthNoOverflow();
    }

    public static function month(string $period): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $period, config('app.timezone'))->startOfMonth();
    }

    public function build(Customer $customer, CarbonImmutable $month): array
    {
        $from = $month->setTimezone(config('app.timezone'))->startOfMonth();
        $to = $from->addMonthNoOverflow();
        $sites = $customer->websites()->where('status', '!=', 'removing')->orderBy('domain')->get();

        $report = [
            'period' => $from->format('Y-m'),
            'from' => $from,
            'to' => $to,
            'customer' => $customer,
            'sites' => $sites->mapWithKeys(fn (Website $w) => [$w->domain => [
                'domain' => $w->domain, 'ssl' => $w->ssl_status === 'active', 'php' => $w->php_version,
                'status' => $w->status, 'disk_used_mb' => (int) $w->disk_used_mb, 'disk_quota_mb' => $w->disk_quota_mb,
            ]])->all(),
        ];
        $report['visitors'] = $this->visitors($sites, $from, $to, $report['sites']);
        $report['uptime'] = $this->uptime($customer, $from, $to, $report['sites']);
        $report['backups'] = $this->backups($customer, $sites, $from, $to, $report['sites']);
        $report['security'] = $this->security($customer, $sites, $from, $to);
        $report['mail'] = $this->mail($customer, $from, $to);
        $report['updates'] = $this->updates($customer, $sites, $from, $to);
        $report['usage'] = $this->usage($sites, $from);

        return $report;
    }

    /** The headline numbers, kept with a sent report for the admin list. */
    public static function summary(array $r): array
    {
        return array_filter([
            'visitors' => $r['visitors']['total'] ?? null,
            'uptime' => $r['uptime']['average'] ?? null,
            'backups' => $r['backups'] ? $r['backups']['total'] : null,
            'fixed' => $r['security']['resolved'] ?? null,
            'spam' => $r['mail']['spam'] ?? null,
            'sites' => count($r['sites']),
        ], fn ($v) => $v !== null);
    }

    private function visitors(Collection $sites, CarbonImmutable $from, CarbonImmutable $to, array &$perSite): ?array
    {
        if ($sites->isEmpty() || ! $this->hasTable('visit_stats')) {
            return null;
        }
        $ids = $sites->pluck('domain', 'id');
        $rows = DB::table('visit_stats')->where('resolution', 'day')->whereIn('website_id', $ids->keys())
            ->where('period_start', '>=', $from->subMonthNoOverflow())->where('period_start', '<', $to)
            ->get(['website_id', 'period_start', 'visitors', 'pageviews', 'bytes']);
        if ($rows->isEmpty()) {
            return null;
        }
        $daily = array_fill(1, $from->daysInMonth, 0);
        $total = ['visitors' => 0, 'pageviews' => 0, 'bytes' => 0, 'previous' => 0];
        foreach ($rows as $row) {
            $day = CarbonImmutable::parse($row->period_start, config('app.timezone'));
            $domain = $ids[$row->website_id];
            if ($day->lt($from)) {
                $total['previous'] += (int) $row->visitors;
                $perSite[$domain]['visitors_previous'] = ($perSite[$domain]['visitors_previous'] ?? 0) + (int) $row->visitors;

                continue;
            }
            $daily[$day->day] += (int) $row->visitors;
            foreach (['visitors', 'pageviews', 'bytes'] as $f) {
                $total[$f] += (int) $row->{$f};
                $perSite[$domain][$f] = ($perSite[$domain][$f] ?? 0) + (int) $row->{$f};
            }
        }
        $best = array_keys($daily, max($daily))[0];

        return [
            'total' => $total['visitors'],
            'pageviews' => $total['pageviews'],
            'bytes' => $total['bytes'],
            'previous' => $total['previous'],
            'change' => $total['previous'] > 0 ? round(($total['visitors'] - $total['previous']) / $total['previous'] * 100) : null,
            'daily' => array_values($daily),
            'best_day' => $daily[$best] > 0 ? $from->setDay($best) : null,
            'best_day_visitors' => $daily[$best],
        ];
    }

    private function uptime(Customer $customer, CarbonImmutable $from, CarbonImmutable $to, array &$perSite): ?array
    {
        $data = Reports::get('uptime', $customer, $from, $to);
        $list = collect($data['sites'] ?? [])->filter(fn ($s, $domain) => is_array($s) && isset($perSite[$domain], $s['uptime']));
        if ($list->isEmpty()) {
            return null;
        }
        foreach ($list as $domain => $s) {
            $perSite[$domain]['uptime'] = (float) $s['uptime'];
            $perSite[$domain]['response_ms'] = isset($s['response_ms']) ? (int) $s['response_ms'] : null;
        }

        return [
            'average' => round($list->avg(fn ($s) => (float) $s['uptime']), 2),
            'lowest' => round($list->min(fn ($s) => (float) $s['uptime']), 2),
            'downtime_minutes' => (int) $list->sum(fn ($s) => (int) ($s['downtime_minutes'] ?? 0)),
            'incidents' => (int) $list->sum(fn ($s) => (int) ($s['incidents'] ?? 0)),
            'response_ms' => ($r = $list->pluck('response_ms')->filter(fn ($v) => is_numeric($v))) ->isNotEmpty() ? (int) round($r->avg()) : null,
        ];
    }

    private function backups(Customer $customer, Collection $sites, CarbonImmutable $from, CarbonImmutable $to, array &$perSite): ?array
    {
        if ($sites->isEmpty()) {
            return null;
        }
        $server = 0;
        $last = null;
        $snapshots = $this->snapshots();
        foreach ($sites as $site) {
            $count = 0;
            foreach ($snapshots['site:'.$site->domain] ?? [] as $snap) {
                $time = CarbonImmutable::parse($snap['time']);
                if ($time->gte($from) && $time->lt($to)) {
                    $count++;
                    $last = $last === null || $time->gt($last) ? $time : $last;
                }
            }
            $perSite[$site->domain]['backups'] = $count;
            $server += $count;
        }

        $own = 0;
        $failed = 0;
        if ($this->hasTable('client_backups')) {
            $rows = DB::table('client_backups')->where('customer_id', $customer->id)->where('action', 'backup')
                ->where('started_at', '>=', $from)->where('started_at', '<', $to)
                ->selectRaw('status, count(*) as n, max(finished_at) as last')->groupBy('status')->get()->keyBy('status');
            $own = (int) ($rows['succeeded']->n ?? 0);
            $failed = (int) ($rows['failed']->n ?? 0);
            if ($own && ($ownLast = CarbonImmutable::parse($rows['succeeded']->last)) && ($last === null || $ownLast->gt($last))) {
                $last = $ownLast;
            }
        }
        if ($snapshots === [] && $own === 0 && $failed === 0) {
            return null;
        }

        return [
            'server' => $snapshots === [] ? null : $server,
            'own' => $own,
            'own_failed' => $failed,
            'total' => $server + $own,
            'last' => $last?->setTimezone(config('app.timezone')),
            'sites_covered' => collect($perSite)->filter(fn ($s) => ($s['backups'] ?? 0) > 0)->count(),
        ];
    }

    /** @return array<string, list<array>> */
    private function snapshots(): array
    {
        if ($this->snapshots !== null) {
            return $this->snapshots;
        }
        $this->snapshots = [];
        try {
            foreach ($this->agent->call('backup.targets')['targets'] ?? [] as $target) {
                if (($target['enabled'] ?? true) === false) {
                    continue;
                }
                foreach ($this->agent->call('backup.list', ['target' => $target['name']], 180)['snapshots'] ?? [] as $snap) {
                    if (isset($snap['scope'], $snap['time'])) {
                        $this->snapshots[$snap['scope']][] = $snap;
                    }
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $this->snapshots;
    }

    private function security(Customer $customer, Collection $sites, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [
            'ssl_active' => $sites->where('ssl_status', 'active')->count(),
            'sites' => $sites->count(),
            'scanned' => false, 'resolved' => 0, 'found' => 0, 'open' => 0, 'open_urgent' => 0,
        ];
        if ($this->hasTable('security_findings') && $sites->isNotEmpty()) {
            $q = fn () => DB::table('security_findings')->whereIn('website_id', $sites->pluck('id'));
            $out['scanned'] = true;
            $out['resolved'] = $q()->where('status', 'resolved')->where('resolved_at', '>=', $from)->where('resolved_at', '<', $to)->count();
            $out['found'] = $q()->where('first_seen_at', '>=', $from)->where('first_seen_at', '<', $to)->count();
            $out['open'] = $q()->where('status', 'open')->count();
            $out['open_urgent'] = $q()->where('status', 'open')->where(fn ($w) => $w->where('kind', 'malware')->orWhereIn('severity', ['critical', 'high']))->count();
        }

        return $out;
    }

    private function mail(Customer $customer, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if (! $this->hasTable('mail_log_messages')) {
            return null;
        }
        $base = fn () => DB::table('mail_log_messages')->where('customer_id', $customer->id)
            ->where('logged_at', '>=', $from)->where('logged_at', '<', $to);
        $spamActions = ['reject', 'soft reject', 'add header', 'rewrite subject'];
        $received = $base()->where('direction', 'incoming')->whereIn('status', ['sent', 'mixed'])
            ->where(fn ($w) => $w->whereNull('spam_action')->orWhereNotIn('spam_action', $spamActions))->count();
        $spam = $base()->where('direction', 'incoming')
            ->where(fn ($w) => $w->where('status', 'rejected')->orWhereIn('spam_action', $spamActions))->count();
        $sent = $base()->where('direction', 'outgoing')->whereIn('status', ['sent', 'mixed'])->count();
        if ($received + $spam + $sent === 0) {
            return null;
        }

        return ['received' => $received, 'sent' => $sent, 'spam' => $spam];
    }

    private function updates(Customer $customer, Collection $sites, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $wordpress = 0;
        $waiting = 0;
        if ($this->hasTable('wordpress_installs') && $sites->isNotEmpty()) {
            $installs = DB::table('wordpress_installs')->whereIn('website_id', $sites->pluck('id'))->get();
            $wordpress = $installs->filter(fn ($i) => $i->last_update_at && $i->last_update_status === 'succeeded'
                && CarbonImmutable::parse($i->last_update_at)->between($from, $to->subSecond()))->count();
            $waiting = (int) $installs->sum(fn ($i) => (int) $i->outdated_plugins + (int) $i->outdated_themes + ($i->core_update ? 1 : 0));
        }
        // Updates started from the panel this month (the column above only keeps the latest per install).
        $wordpress = max($wordpress, ActivityLog::where('customer_id', $customer->id)->where('action', 'wordpress.update')
            ->where('created_at', '>=', $from)->where('created_at', '<', $to)->count());

        $server = null;
        if ($this->hasTable('server_task_runs')) {
            $server = DB::table('server_task_runs')->where('task', 'upgrade')->where('status', 'succeeded')
                ->where('started_at', '>=', $from)->where('started_at', '<', $to)->count();
        }

        return [
            'wordpress' => $wordpress,
            'wordpress_sites' => $this->hasTable('wordpress_installs') && $sites->isNotEmpty()
                ? DB::table('wordpress_installs')->whereIn('website_id', $sites->pluck('id'))->count() : 0,
            'waiting' => $waiting,
            'server' => $server,
            'php' => $sites->pluck('php_version')->filter()->unique()->sort()->values()->all(),
        ];
    }

    private function usage(Collection $sites, CarbonImmutable $from): array
    {
        $quota = $sites->every(fn ($s) => $s->disk_quota_mb) ? (int) $sites->sum('disk_quota_mb') : null;
        $traffic = $this->hasTable('website_traffic') && $sites->isNotEmpty()
            ? (int) DB::table('website_traffic')->whereIn('website_id', $sites->pluck('id'))->where('month', $from->format('Y-m'))->sum('bytes')
            : 0;

        return ['disk_used_mb' => (int) $sites->sum('disk_used_mb'), 'disk_quota_mb' => $quota ?: null, 'traffic_bytes' => $traffic];
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }
}
