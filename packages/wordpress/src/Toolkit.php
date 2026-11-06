<?php

declare(strict_types=1);

namespace Rhis\WordPress;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;
use Rhis\WordPress\Models\WordPressInstall;

/** Everything the screens do with WordPress goes through here, and from here to the agent (wp.*). */
final class Toolkit
{
    private const JOBS = 'wordpress.jobs';

    public function __construct(private readonly AgentClient $agent) {}

    /** Find the installs of a website and refresh them; installs that are gone are removed. */
    public function discover(Website $website, bool $withStatus = true): array
    {
        $found = $this->agent->call('wp.detect', ['domain' => $website->domain], 30)['installs'] ?? [];
        $paths = [];
        foreach ($found as $item) {
            $path = (string) ($item['path'] ?? '');
            $paths[] = $path;
            $install = WordPressInstall::firstOrNew(['website_id' => $website->id, 'path' => $path]);
            $install->version = $item['version'] ?? $install->version;
            $install->save();
            if ($withStatus) {
                $this->refresh($install);
            }
        }
        WordPressInstall::where('website_id', $website->id)->whereNotIn('path', $paths)->delete();

        return $paths;
    }

    public function refresh(WordPressInstall $install): WordPressInstall
    {
        try {
            $install->fillFromStatus($this->agent->call('wp.status', $install->target(), 300));
        } catch (AgentException $e) {
            $install->last_error = $e->getMessage();
            $install->checked_at = now();
        }
        $install->save();

        return $install;
    }

    /**
     * Start an update job. $plugins / $themes: 'all', a list of slugs, or false to skip.
     */
    public function update(WordPressInstall $install, bool $core = true, array|string|false $plugins = 'all', array|string|false $themes = 'all', bool $snapshot = true): string
    {
        $job = $this->agent->call('wp.update', $install->target() + [
            'core' => $core, 'plugins' => $plugins, 'themes' => $themes, 'translations' => true,
            'snapshot' => $snapshot, 'rollback' => $snapshot,
        ], 30)['job'];
        $this->remember($job, 'update', [$install->id], $install->website->customer_id);

        return $job;
    }

    /** One job that updates all given installs one after the other. */
    public function updateMany(iterable $installs, ?int $customerId = null): ?string
    {
        $list = collect($installs)->values();
        if ($list->isEmpty()) {
            return null;
        }
        $job = $this->agent->call('wp.update_many', [
            'installs' => $list->map(fn (WordPressInstall $i) => $i->target())->all(),
            'core' => true, 'plugins' => 'all', 'themes' => 'all', 'translations' => true, 'snapshot' => true, 'rollback' => true,
        ], 30)['job'];
        $this->remember($job, 'update_many', $list->pluck('id')->all(), $customerId);

        return $job;
    }

    public function restore(WordPressInstall $install, string $snapshot): string
    {
        $job = $this->agent->call('wp.restore', $install->target() + ['snapshot' => $snapshot], 30)['job'];
        $this->remember($job, 'restore', [$install->id], $install->website->customer_id);

        return $job;
    }

    public function snapshots(WordPressInstall $install): array
    {
        return $this->agent->call('wp.snapshots', $install->target(), 30)['snapshots'] ?? [];
    }

    public function setCron(WordPressInstall $install, string $mode, int $interval): array
    {
        $cron = $this->agent->call('wp.cron_set', $install->target() + ['mode' => $mode, 'interval' => $interval], 120);
        $install->update(['cron_mode' => $cron['mode'] ?? $mode, 'cron_interval' => $cron['interval'] ?? null]);

        return $cron;
    }

    public function cron(WordPressInstall $install): array
    {
        return $this->agent->call('wp.cron_get', $install->target(), 30);
    }

    public function admins(WordPressInstall $install): array
    {
        return $this->agent->call('wp.users', $install->target(), 60)['users'] ?? [];
    }

    /** Returns the generated password when $password is null. */
    public function setPassword(WordPressInstall $install, int $userId, ?string $password): array
    {
        return $this->agent->call('wp.set_password', $install->target() + array_filter([
            'user_id' => $userId, 'password' => $password,
        ], fn ($v) => $v !== null), 60);
    }

    public function loginLink(WordPressInstall $install, ?int $userId = null): string
    {
        return $this->agent->call('wp.login_link', $install->target() + array_filter(['user_id' => $userId]), 60)['url'];
    }

    // ------------------------------------------------------------- jobs

    private function remember(string $id, string $kind, array $installIds, ?int $customerId): void
    {
        $jobs = array_filter(Cache::get(self::JOBS, []), fn ($j) => $j['id'] !== $id);
        array_unshift($jobs, [
            'id' => $id, 'kind' => $kind, 'installs' => $installIds, 'customer_id' => $customerId,
            'user' => auth()->user()?->name, 'started_at' => now()->toIso8601String(), 'processed' => false,
        ]);
        Cache::forever(self::JOBS, array_slice(array_values($jobs), 0, 100));
    }

    public function job(string $id): ?array
    {
        return collect(Cache::get(self::JOBS, []))->firstWhere('id', $id);
    }

    public function recentJobs(?int $customerId = null, int $limit = 10): array
    {
        return collect(Cache::get(self::JOBS, []))
            ->when($customerId !== null, fn ($c) => $c->where('customer_id', $customerId))
            ->take($limit)->values()->all();
    }

    /** Follow an agent job; when it is finished, store the outcome on the installs (once). */
    public function follow(string $id): ?array
    {
        $meta = $this->job($id);
        if (! $meta) {
            return null;
        }
        $state = $this->agent->call('job.status', ['job' => $id], 10);
        if (($state['status'] ?? 'running') !== 'running' && ! $meta['processed']) {
            $this->process($meta, $state);
        }

        return $state;
    }

    /** Store finished jobs that nobody looked at (scheduled every few minutes). */
    public function collect(): int
    {
        $done = 0;
        foreach (Cache::get(self::JOBS, []) as $meta) {
            if ($meta['processed']) {
                continue;
            }
            try {
                $state = $this->agent->call('job.status', ['job' => $meta['id']], 10);
            } catch (AgentException) {
                continue;
            }
            if (($state['status'] ?? 'running') !== 'running') {
                $this->process($meta, $state);
                $done++;
            }
        }

        return $done;
    }

    private function process(array $meta, array $state): void
    {
        $jobs = Cache::get(self::JOBS, []);
        foreach ($jobs as &$j) {
            if ($j['id'] === $meta['id']) {
                $j['processed'] = true;
            }
        }
        unset($j);
        Cache::forever(self::JOBS, $jobs);

        if (! in_array($meta['kind'], ['update', 'update_many'], true)) {
            foreach (WordPressInstall::whereIn('id', $meta['installs'])->get() as $install) {
                $this->refresh($install);
            }

            return;
        }
        $results = $meta['kind'] === 'update_many'
            ? ($state['result']['results'] ?? [])
            : [($state['status'] === 'succeeded' ? ['ok' => true] + ($state['result'] ?? []) : ['ok' => false] + ($state['error']['details'] ?? []) + ['error' => $state['error']['message'] ?? null])];
        $installs = WordPressInstall::with('website')->whereIn('id', $meta['installs'])->get();
        foreach ($installs as $install) {
            $r = collect($results)->first(fn ($r) => ($r['domain'] ?? $install->website->domain) === $install->website->domain
                && ($r['path'] ?? $install->path) === $install->path) ?? [];
            $detail = $r['result'] ?? $r;
            $status = ($detail['rolled_back'] ?? false) ? 'rolled_back' : (($r['ok'] ?? false) ? 'succeeded' : 'failed');
            $install->last_update_at = now();
            $install->last_update_status = $status;
            $install->last_update_summary = [
                'core' => $detail['updated']['core'] ?? null,
                'plugins' => $detail['updated']['plugins'] ?? [],
                'themes' => $detail['updated']['themes'] ?? [],
                'errors' => array_values(array_filter([...($detail['errors'] ?? []), $r['error'] ?? null])),
                'health' => $detail['health'] ?? null,
            ];
            if (isset($detail['status']) && is_array($detail['status'])) {
                $install->fillFromStatus($detail['status']);
            }
            $install->save();
        }
    }

    /** Totals for the security screen. */
    public static function summary(?int $customerId = null): array
    {
        $q = fn () => WordPressInstall::query()->when($customerId, fn ($q) => $q->forCustomer($customerId));

        return [
            'installs' => $q()->count(),
            'outdated_installs' => $q()->outdated()->count(),
            'core' => $q()->whereNotNull('core_update')->count(),
            'plugins' => (int) $q()->sum('outdated_plugins'),
            'themes' => (int) $q()->sum('outdated_themes'),
            'checked_at' => $q()->max('checked_at'),
        ];
    }
}
