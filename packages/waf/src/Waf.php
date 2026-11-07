<?php

declare(strict_types=1);

namespace Rhis\Waf;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Rhis\Waf\Models\WafEvent;
use Rhis\Waf\Models\WafSite;
use Throwable;

/**
 * The web application firewall settings, pushing them to the server and
 * collecting what the firewall found.
 *
 * Settings live in the panel (server-wide in Settings, per site in
 * waf_sites). Every change sends the complete set to the agent (waf.apply),
 * which writes the ModSecurity files and reloads Nginx after nginx -t.
 */
final class Waf
{
    public const MODES = ['block', 'detect', 'off'];

    public const PARANOIA = [1, 2, 3, 4];

    private const KEY_DEFAULT = 'waf.default_mode';

    private const KEY_PARANOIA = 'waf.paranoia';

    private const KEY_THRESHOLD = 'waf.threshold';

    private const KEY_EXCEPTIONS = 'waf.exceptions';

    private const KEY_CLIENT = 'waf.client_control';

    private const KEY_CURSOR = 'waf.cursor';

    private const KEY_LAST = 'waf.last_apply';

    private const KEY_HASH = 'waf.applied_hash';

    public function __construct(private readonly Settings $settings, private readonly AgentClient $agent) {}

    // ------------------------------------------------------------ settings

    public function defaultMode(): string
    {
        $mode = (string) $this->settings->get(self::KEY_DEFAULT, config('waf.default_mode', 'detect'));

        return in_array($mode, self::MODES, true) ? $mode : 'detect';
    }

    public function paranoia(): int
    {
        return max(1, min(4, (int) $this->settings->get(self::KEY_PARANOIA, 1)));
    }

    public function threshold(): int
    {
        return max(2, min(100, (int) $this->settings->get(self::KEY_THRESHOLD, 5)));
    }

    public function clientControl(): bool
    {
        return (bool) $this->settings->get(self::KEY_CLIENT, true);
    }

    /** Exceptions for every website. */
    public function exceptions(): array
    {
        return json_decode((string) $this->settings->get(self::KEY_EXCEPTIONS, '[]'), true) ?: [];
    }

    public function saveSettings(string $defaultMode, int $paranoia, int $threshold, bool $clientControl): void
    {
        $this->settings->set(self::KEY_DEFAULT, $defaultMode);
        $this->settings->set(self::KEY_PARANOIA, $paranoia);
        $this->settings->set(self::KEY_THRESHOLD, $threshold);
        $this->settings->set(self::KEY_CLIENT, $clientControl ? '1' : '0');
    }

    public function saveExceptions(array $exceptions): void
    {
        $this->settings->set(self::KEY_EXCEPTIONS, json_encode(array_values($exceptions)));
    }

    public function forSite(Website $website): WafSite
    {
        return WafSite::firstOrNew(['website_id' => $website->id], ['mode' => null, 'exceptions' => []]);
    }

    /** The mode a site really runs in (its own choice, or the server default). */
    public function modeFor(?WafSite $site): string
    {
        return $site?->mode ?? $this->defaultMode();
    }

    /**
     * An exception from form input. Rule ids are numbers; path and parameter
     * are optional and validated like the agent does.
     *
     * @return array{rule: int, path: ?string, param: ?string, comment: string, added_by: ?string, added_at: string}
     */
    public static function exception(int $rule, ?string $path, ?string $param, ?string $comment = null): array
    {
        return [
            'rule' => $rule,
            'path' => ($path = trim((string) $path)) === '' ? null : $path,
            'param' => ($param = trim((string) $param)) === '' ? null : $param,
            'comment' => mb_substr(trim((string) $comment), 0, 80),
            'added_by' => auth()->user()?->name,
            'added_at' => now()->toIso8601String(),
        ];
    }

    /** Laravel validation rules for one exception in a form. */
    public static function exceptionRules(): array
    {
        return [
            'rule' => ['required', 'integer', 'between:1,99999999'],
            'path' => ['nullable', 'string', 'max:200', 'regex:#^/[A-Za-z0-9._~%/+:@=,-]*$#'],
            'param' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.\-\[\]]+$/'],
            'comment' => ['nullable', 'string', 'max:80'],
        ];
    }

    /** Adds an exception unless the same one is there; returns the new list. */
    public static function addTo(array $list, array $exception): array
    {
        foreach ($list as $e) {
            if ((int) $e['rule'] === $exception['rule'] && ($e['path'] ?? null) === $exception['path'] && ($e['param'] ?? null) === $exception['param']) {
                return $list;
            }
        }
        $list[] = $exception;

        return array_values($list);
    }

    // ------------------------------------------------------------ server

    private static function strip(array $exceptions): array
    {
        return array_values(array_map(fn ($e) => ['rule' => (int) $e['rule'], 'path' => $e['path'] ?? null, 'param' => $e['param'] ?? null], $exceptions));
    }

    /** Everything the agent needs, in the shape of waf.apply. */
    public function payload(): array
    {
        $rows = WafSite::query()->get()->keyBy('website_id');
        $sites = Website::query()->orderBy('domain')->get(['id', 'domain'])
            ->map(fn (Website $w) => [
                'domain' => $w->domain,
                'mode' => $this->modeFor($rows[$w->id] ?? null),
                'exceptions' => self::strip($rows[$w->id]->exceptions ?? []),
            ])->values()->all();

        return [
            'paranoia' => $this->paranoia(),
            'threshold' => $this->threshold(),
            'exceptions' => self::strip($this->exceptions()),
            'sites' => $sites,
        ];
    }

    /** Push the settings to the server; throws when the agent is unreachable or Nginx refused them. */
    public function apply(): array
    {
        $payload = $this->payload();
        try {
            $result = $this->agent->call('waf.apply', $payload, 180);
            $this->settings->set(self::KEY_LAST, json_encode(['ok' => true, 'at' => now()->toIso8601String(), 'missing' => $result['missing'] ?? []]));
            $this->settings->set(self::KEY_HASH, md5(json_encode($payload)));
            Cache::forget('waf.status');

            return $result;
        } catch (AgentException $e) {
            $this->settings->set(self::KEY_LAST, json_encode(['ok' => false, 'at' => now()->toIso8601String(), 'error' => $e->getMessage()]));
            throw $e;
        }
    }

    /** Apply when something changed since the last time, or a new site was not on the server yet. */
    public function applyIfNeeded(): bool
    {
        $last = $this->lastApply();
        if ($last && $last['ok'] && empty($last['missing'])
            && $this->settings->get(self::KEY_HASH) === md5(json_encode($this->payload()))) {
            return false;
        }
        $this->apply();

        return true;
    }

    /** @return array{ok: bool, at: string, error?: string, missing?: list<string>}|null */
    public function lastApply(): ?array
    {
        return json_decode((string) $this->settings->get(self::KEY_LAST, 'null'), true);
    }

    /** Engine status from the server, cached for a minute; null when the agent is unreachable. */
    public function status(): ?array
    {
        return Cache::remember('waf.status', 60, function () {
            try {
                return $this->agent->call('waf.status');
            } catch (AgentException) {
                return null;
            }
        });
    }

    // ------------------------------------------------------------ events

    /** Fetch new events from the server. Returns how many were stored. */
    public function collect(int $rounds = 10): int
    {
        $sites = Website::query()->pluck('id', 'domain');
        $stored = 0;
        for ($i = 0; $i < $rounds; $i++) {
            $cursor = json_decode((string) $this->settings->get(self::KEY_CURSOR, 'null'), true);
            $answer = $this->agent->call('waf.events', array_filter(['cursor' => $cursor, 'limit' => 2000]), 120);
            $rows = [];
            foreach ($answer['events'] ?? [] as $e) {
                $rows[] = $this->row($e, $sites);
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                $stored += DB::table('waf_events')->insertOrIgnore($chunk);
            }
            $this->settings->set(self::KEY_CURSOR, json_encode($answer['cursor'] ?? null));
            if (empty($answer['more'])) {
                break;
            }
        }

        return $stored;
    }

    private function row(array $e, $sites): array
    {
        try {
            $at = Carbon::parse((string) ($e['time'] ?? ''));
        } catch (Throwable) {
            $at = now();
        }
        $rules = array_values(array_filter($e['rules'] ?? [], 'is_array'));
        $domain = mb_substr((string) ($e['domain'] ?? ''), 0, 255);

        return [
            'website_id' => $sites[$domain] ?? null,
            'domain' => $domain,
            'request_id' => mb_substr((string) ($e['id'] ?? ''), 0, 64),
            'occurred_at' => $at,
            'ip' => mb_substr((string) ($e['ip'] ?? ''), 0, 45) ?: null,
            'method' => mb_substr((string) ($e['method'] ?? ''), 0, 10) ?: null,
            'uri' => mb_substr((string) ($e['uri'] ?? ''), 0, 500) ?: null,
            'host' => mb_substr((string) ($e['host'] ?? ''), 0, 255) ?: null,
            'user_agent' => mb_substr((string) ($e['user_agent'] ?? ''), 0, 255) ?: null,
            'status' => max(0, min(999, (int) ($e['status'] ?? 0))),
            'blocked' => (bool) ($e['blocked'] ?? false),
            'mode' => ($e['mode'] ?? '') === 'block' ? 'block' : 'detect',
            'score' => isset($e['score']) ? max(0, min(65535, (int) $e['score'])) : null,
            'rules' => json_encode($rules),
            'rule_ids' => ','.implode(',', array_map(fn ($r) => (int) ($r['id'] ?? 0), $rules)).',',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    public function prune(): int
    {
        return WafEvent::where('occurred_at', '<', now()->subDays((int) config('waf.keep_days', 30)))->delete();
    }

    /** Numbers for the Security center and the overview, over the given website ids (null = all). */
    public function summary(?array $websiteIds = null): array
    {
        $q = fn () => WafEvent::query()->when($websiteIds !== null, fn ($q) => $q->whereIn('website_id', $websiteIds));
        $day = now()->subDay();
        $week = now()->subDays(7);

        $topSites = $q()->where('occurred_at', '>=', $week)->whereNotNull('website_id')
            ->selectRaw('website_id, count(*) as hits, sum(case when blocked then 1 else 0 end) as blocked')
            ->groupBy('website_id')->orderByDesc('hits')->limit(5)->get();
        $names = Website::whereIn('id', $topSites->pluck('website_id'))->pluck('domain', 'id');

        $ruleCounts = [];
        $messages = [];
        $q()->where('occurred_at', '>=', $week)->latest('occurred_at')->limit(5000)->get(['rules'])
            ->each(function (WafEvent $e) use (&$ruleCounts, &$messages) {
                foreach ($e->rules as $r) {
                    $id = (int) ($r['id'] ?? 0);
                    $ruleCounts[$id] = ($ruleCounts[$id] ?? 0) + 1;
                    $messages[$id] ??= (string) ($r['message'] ?? '');
                }
            });
        arsort($ruleCounts);

        $modes = ['block' => 0, 'detect' => 0, 'off' => 0];
        $rows = WafSite::query()->get()->keyBy('website_id');
        Website::query()->when($websiteIds !== null, fn ($w) => $w->whereIn('id', $websiteIds))->pluck('id')
            ->each(function ($id) use (&$modes, $rows) {
                $modes[$this->modeFor($rows[$id] ?? null)]++;
            });

        return [
            'blocked_24h' => $q()->where('occurred_at', '>=', $day)->where('blocked', true)->count(),
            'detected_24h' => $q()->where('occurred_at', '>=', $day)->where('blocked', false)->count(),
            'blocked_7d' => $q()->where('occurred_at', '>=', $week)->where('blocked', true)->count(),
            'attackers_7d' => $q()->where('occurred_at', '>=', $week)->distinct()->count('ip'),
            'top_sites' => $topSites->map(fn ($r) => ['id' => $r->website_id, 'domain' => $names[$r->website_id] ?? '?', 'hits' => (int) $r->hits, 'blocked' => (int) $r->blocked])->all(),
            'top_rules' => collect($ruleCounts)->take(5)->map(fn ($n, $id) => ['id' => $id, 'hits' => $n, 'message' => $messages[$id] ?? ''])->values()->all(),
            'modes' => $modes,
            'default_mode' => $this->defaultMode(),
        ];
    }

    /** Blocked and detected per day, oldest first, for the chart. */
    public function perDay(int $days = 14, ?array $websiteIds = null): array
    {
        $from = now()->startOfDay()->subDays($days - 1);
        $counts = WafEvent::query()->when($websiteIds !== null, fn ($q) => $q->whereIn('website_id', $websiteIds))
            ->where('occurred_at', '>=', $from)
            ->selectRaw('date(occurred_at) as d, blocked, count(*) as n')->groupBy('d', 'blocked')->get()
            ->groupBy('d');
        $out = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $day = $counts[$d->toDateString()] ?? collect();
            $out[] = [
                'label' => $d->isoFormat('D MMM'),
                'blocked' => (int) $day->filter(fn ($r) => (bool) $r->blocked)->sum('n'),
                'detected' => (int) $day->reject(fn ($r) => (bool) $r->blocked)->sum('n'),
            ];
        }

        return $out;
    }
}
