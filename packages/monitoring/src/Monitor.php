<?php

declare(strict_types=1);

namespace Rhis\Monitoring;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Rhis\Monitoring\Models\Check;
use Rhis\Monitoring\Models\Daily;
use Rhis\Monitoring\Models\Incident;
use Rhis\Monitoring\Models\Sample;
use Throwable;

/**
 * One monitoring round: ask the agent, turn the answers into states,
 * open and close incidents and hand new events to the alerts.
 */
final class Monitor
{
    /** Keys seen in this round (anything else is gone: removed website, uninstalled service). */
    private array $seen = [];

    /** @var list<array{type: string, incident: Incident}> */
    private array $events = [];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Options $options,
        private readonly Alerts $alerts,
    ) {}

    public function due(?CarbonInterface $now = null): bool
    {
        if (! $this->options->get('enabled')) {
            return false;
        }
        $last = (int) $this->options->state('last_run_at', 0);
        $now ??= now();

        // A little slack so a run that started a few seconds late does not skip a round.
        return $now->getTimestamp() - $last >= max(1, $this->options->get('interval')) * 60 - 20;
    }

    /** @return array{checked: int, problems: int, events: int} */
    public function run(): array
    {
        $this->seen = [];
        $this->events = [];
        $now = now();
        $websites = Website::where('status', 'active')->orderBy('id')->get(['id', 'domain', 'ssl_status', 'customer_id']);
        $this->options->setState('last_run_at', $now->getTimestamp());

        try {
            $res = $this->agent->call('monitor.check', [
                'sites' => $websites->map(fn (Website $w) => ['domain' => $w->domain, 'ssl' => $w->ssl_status === 'active'])->values()->all(),
            ], 600);
        } catch (AgentException $e) {
            // Nothing else can be checked; keep every other state as it is.
            $this->apply('agent', 'agent', 'whp-agent', 'critical', mb_substr($e->getMessage(), 0, 300), [], null, 2);
            $this->finish($now, prune: false);

            return ['checked' => 0, 'problems' => 1, 'events' => count($this->events)];
        }
        $this->apply('agent', 'agent', 'whp-agent', 'ok', null, [], null);

        $byDomain = collect($res['sites'] ?? [])->keyBy('domain');
        foreach ($websites as $w) {
            if ($site = $byDomain->get($w->domain)) {
                $this->website($w, $site, $now);
            }
        }
        foreach ($res['disks'] ?? [] as $d) {
            $this->disk($d);
        }
        $this->services($res['services'] ?? []);
        $this->server($res);

        DB::transaction(fn () => $this->vanished($now));
        $this->finish($now);

        return [
            'checked' => count($this->seen),
            'problems' => Check::whereIn('status', ['warning', 'critical'])->count(),
            'events' => count($this->events),
        ];
    }

    // ------------------------------------------------------------ websites

    private function website(Website $w, array $site, CarbonInterface $now): void
    {
        $ok = (bool) ($site['ok'] ?? false);
        $ms = isset($site['ms']) ? (int) $site['ms'] : null;
        $code = (int) ($site['code'] ?? 0);
        Sample::create(['website_id' => $w->id, 'checked_at' => $now, 'ok' => $ok, 'code' => $code, 'ms' => $ms]);
        $this->daily("site:{$w->id}", $w->id, $now, $ok, $ok ? $ms : null);

        $detail = $ok ? null : trim(($code ? 'HTTP '.$code : '').' '.($site['error'] ?? ''));
        $this->apply("site:{$w->id}", 'website', $w->domain, $ok ? 'ok' : 'critical', $detail ?: null,
            ['code' => $code, 'ms' => $ms, 'slow' => $ms !== null && $ms >= $this->options->get('slow_ms')], $w, $this->options->get('fail_after'));

        // The certificate can only be judged while the website answers.
        $ssl = $site['ssl'] ?? null;
        if ($ssl === null) {
            if ($w->ssl_status !== 'active') {
                $this->forget("ssl:{$w->id}");
            } else {
                $this->seen["ssl:{$w->id}"] = true;
            }

            return;
        }
        if (! $ok && ! ($ssl['ok'] ?? false)) {
            $this->seen["ssl:{$w->id}"] = true;

            return;
        }
        $days = $ssl['days_left'] ?? null;
        $status = match (true) {
            ! ($ssl['ok'] ?? false), $days === null => 'critical',
            $days <= $this->options->get('ssl_critical_days') => 'critical',
            $days <= $this->options->get('ssl_warning_days') => 'warning',
            default => 'ok',
        };
        $expires = isset($ssl['expires_at']) ? date('Y-m-d', (int) $ssl['expires_at']) : null;
        $detail = match (true) {
            $status === 'ok' => null,
            ($ssl['matches'] ?? true) === false => 'Certificate is for another domain',
            $expires !== null => 'Expires '.$expires,
            default => $ssl['error'] ?? null,
        };
        $this->apply("ssl:{$w->id}", 'ssl', $w->domain, $status, $detail,
            ['days_left' => $days, 'expires_at' => $ssl['expires_at'] ?? null, 'issuer' => $ssl['issuer'] ?? null], $w, 1);
    }

    private function daily(string $key, ?int $websiteId, CarbonInterface $now, bool $up, ?int $ms): void
    {
        $day = $now->copy()->timezone(config('app.timezone'))->toDateString();
        $row = Daily::firstOrCreate(['key' => $key, 'day' => $day], ['website_id' => $websiteId]);
        $inc = ['checks' => DB::raw('checks + 1')];
        if ($up) {
            $inc['up'] = DB::raw('up + 1');
        } else {
            $inc['down_seconds'] = DB::raw('down_seconds + '.(max(1, $this->options->get('interval')) * 60));
        }
        if ($ms !== null) {
            $inc['ms_total'] = DB::raw('ms_total + '.max(0, $ms));
            $inc['ms_count'] = DB::raw('ms_count + 1');
        }
        Daily::whereKey($row->id)->update($inc);
    }

    // ------------------------------------------------------------ server

    private function disk(array $d): void
    {
        $mount = (string) ($d['mount'] ?? '?');
        $pct = (float) ($d['pct'] ?? 0);
        $status = $pct >= $this->options->get('disk_critical') ? 'critical' : ($pct >= $this->options->get('disk_warning') ? 'warning' : 'ok');
        $this->apply("disk:$mount", 'disk', $mount, $status, $status === 'ok' ? null : $pct.' %',
            ['pct' => $pct, 'used' => $d['used'] ?? null, 'total' => $d['total'] ?? null, 'free' => $d['free'] ?? null, 'inodes_pct' => $d['inodes_pct'] ?? null], null);
        if (isset($d['inodes_pct'])) {
            $ip = (float) $d['inodes_pct'];
            $this->apply("inode:$mount", 'inode', $mount, $ip >= $this->options->get('inode_warning') ? 'warning' : 'ok',
                $ip >= $this->options->get('inode_warning') ? $ip.' %' : null, ['pct' => $ip], null);
        }
    }

    private function services(array $services): void
    {
        $components = array_fill_keys(array_keys((array) config('monitoring.components')), []);
        foreach ($services as $s) {
            $unit = (string) ($s['service'] ?? '');
            $active = (bool) ($s['active'] ?? false);
            $this->apply("service:$unit", 'service', (string) ($s['label'] ?? $unit), $active ? 'ok' : 'critical',
                $active ? null : (string) ($s['state'] ?? 'stopped'), ['unit' => $unit, 'state' => $s['state'] ?? null], null, $this->options->get('fail_after'));
            if ($key = Components::of($unit)) {
                $components[$key][] = $active;
            }
        }
        $now = now();
        foreach ($components as $key => $states) {
            if (! $states) {
                continue;
            }
            $up = ! in_array(false, $states, true);
            // Partly down (one of the PHP versions) is "warning", nothing running is "critical".
            $status = $up ? 'ok' : (in_array(true, $states, true) ? 'warning' : 'critical');
            $this->apply("component:$key", 'component', $key, $status, null, [], null, $this->options->get('fail_after'), incident: false);
            $this->daily("component:$key", null, $now, Check::where('key', "component:$key")->value('status') === 'ok', null);
        }
    }

    private function server(array $res): void
    {
        if ($mem = $res['memory'] ?? null) {
            $pct = (float) ($mem['pct'] ?? 0);
            $bad = $pct >= $this->options->get('memory_warning');
            $this->apply('memory', 'memory', 'Memory', $bad ? 'warning' : 'ok', $bad ? $pct.' %' : null, $mem, null, $this->options->get('fail_after'));
        }
        if ($load = $res['load'] ?? null) {
            $cpus = max(1, (int) ($res['cpus'] ?? 1));
            $per = round(((float) ($load[1] ?? 0)) / $cpus, 2);
            $bad = $per >= $this->options->get('load_warning');
            $this->apply('load', 'load', 'Load', $bad ? 'warning' : 'ok', $bad ? sprintf('%.2f (%d CPU)', $load[1], $cpus) : null,
                ['load' => $load, 'cpus' => $cpus, 'per_cpu' => $per], null, $this->options->get('fail_after'));
        }
        if (array_key_exists('mail_queue', $res) && $res['mail_queue'] !== null) {
            $n = (int) $res['mail_queue'];
            $bad = $n >= $this->options->get('mail_queue_warning');
            $this->apply('mail_queue', 'mail_queue', 'Mail queue', $bad ? 'warning' : 'ok', $bad ? (string) $n : null, ['count' => $n], null, 2);
        }
    }

    // ------------------------------------------------------------ states and incidents

    private function apply(string $key, string $kind, string $label, string $measured, ?string $detail, array $value, ?Website $w, int $failAfter = 1, bool $incident = true): void
    {
        $this->seen[$key] = true;
        $now = now();
        $check = Check::firstOrNew(['key' => $key], ['kind' => $kind, 'status' => 'ok', 'fails' => 0, 'since' => $now]);
        $fails = Severity::isProblem($measured) ? $check->fails + 1 : 0;
        $status = Severity::isProblem($measured) && $fails < max(1, $failAfter) ? ($check->exists && Severity::isProblem($check->status) ? $check->status : 'ok') : $measured;
        if (! $check->exists || $check->status !== $status) {
            $check->since = $now;
        }
        $check->fill([
            'kind' => $kind, 'website_id' => $w?->id, 'label' => mb_substr($label, 0, 191), 'status' => $status, 'fails' => $fails,
            'value' => $value, 'detail' => $detail === null ? null : mb_substr($detail, 0, 500), 'checked_at' => $now,
        ])->save();

        if (! $incident) {
            return;
        }
        $open = Incident::open()->where('key', $key)->latest('id')->first();
        if (Severity::isProblem($status)) {
            if (! $open) {
                Incident::create([
                    'key' => $key, 'kind' => $kind, 'website_id' => $w?->id, 'customer_id' => $w?->customer_id,
                    'title' => $check->label, 'severity' => $status, 'detail' => $check->detail, 'started_at' => $now,
                ]);
            } elseif (Severity::rank($status) > Severity::rank($open->severity)) {
                $open->update(['severity' => $status, 'detail' => $check->detail]);
                if ($open->notified_at) {
                    $this->events[] = ['type' => 'worse', 'incident' => $open];
                }
            } elseif ($check->detail !== null) {
                $open->update(['detail' => $check->detail]);
            }
        } elseif ($open) {
            $this->resolve($open, $now);
        }
    }

    private function resolve(Incident $open, CarbonInterface $now): void
    {
        $open->update(['resolved_at' => $now]);
        // Only report "solved" for problems that were reported.
        if ($open->notified_at) {
            $this->events[] = ['type' => 'recovery', 'incident' => $open];
        }
    }

    private function forget(string $key): void
    {
        foreach (Incident::open()->where('key', $key)->get() as $open) {
            $this->resolve($open, now());
        }
        Check::where('key', $key)->delete();
    }

    /** Checks not seen in this round belong to something that is gone. */
    private function vanished(CarbonInterface $now): void
    {
        Check::whereNotIn('key', array_keys($this->seen))->where('key', '!=', 'agent')->get()
            ->each(fn (Check $c) => $this->forget($c->key));
    }

    private function finish(CarbonInterface $now, bool $prune = true): void
    {
        $paused = $this->paused();
        // New problems are reported once they are confirmed and no maintenance is running.
        if (! $paused) {
            foreach (Incident::open()->whereNull('notified_at')->get() as $i) {
                $this->events[] = ['type' => 'problem', 'incident' => $i];
            }
            $hours = $this->options->get('reminder_hours');
            if ($hours > 0) {
                foreach (Incident::open()->whereNotNull('notified_at')->where('notified_at', '<=', $now->copy()->subHours($hours))->get() as $i) {
                    $this->events[] = ['type' => 'reminder', 'incident' => $i];
                }
            }
        }
        if ($this->events) {
            $this->alerts->send($this->events, $paused);
        }
        $this->options->setState('last_result', ['at' => $now->getTimestamp(), 'paused' => $paused, 'events' => count($this->events)]);
        $this->heartbeat();
        if ($prune) {
            Sample::where('checked_at', '<', $now->copy()->subDays((int) config('monitoring.samples_days', 7)))->delete();
        }
    }

    /** While server maintenance (updates, reboot) runs, problems are recorded but not reported yet. */
    public function paused(): bool
    {
        if (! class_exists(\Rhis\ServerAdmin\Models\ServerTaskRun::class)) {
            return false;
        }
        try {
            return \Rhis\ServerAdmin\Models\ServerTaskRun::running()->whereIn('task', ['maintenance', 'reboot'])->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** Ping the external heartbeat URL: when the pings stop, that service alerts (the server itself is down). */
    private function heartbeat(): void
    {
        $url = trim((string) $this->options->get('heartbeat_url'));
        if ($url === '' || ! str_starts_with($url, 'https://')) {
            return;
        }
        try {
            $ok = Http::timeout(10)->connectTimeout(5)->get($url)->successful();
            $this->options->setState('heartbeat', ['at' => now()->getTimestamp(), 'ok' => $ok]);
        } catch (Throwable $e) {
            $this->options->setState('heartbeat', ['at' => now()->getTimestamp(), 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 200)]);
        }
    }
}
