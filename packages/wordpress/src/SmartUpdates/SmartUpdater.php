<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates;

use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Rhis\ServerAdmin\Maintenance;
use Rhis\Vulnerabilities\Models\Finding;
use Rhis\WordPress\Models\WordPressInstall;
use Rhis\WordPress\SmartUpdates\Mail\SmartUpdateReport;
use Rhis\WordPress\SmartUpdates\Models\UpdatePolicy;
use Rhis\WordPress\SmartUpdates\Models\UpdateRun;
use RuntimeException;
use Throwable;

/**
 * Scheduled WordPress updates in the maintenance window, checked with screenshots.
 *
 * Every minute (whp:wordpress:smart-updates) the panel follows the running jobs
 * and, inside the window, starts the next installs (a few at a time). The agent
 * does the work per install (wp.smart_update): update, compare screenshots, roll
 * back when the site looks different or breaks. The panel stores the outcome and
 * the screenshots, and mails the customer (and the admins about problems).
 *
 * The window is server management's maintenance window when that is on; the
 * updates wait while an OS update or reboot of that window is still busy.
 * Without it the own window from config/smart-updates.php is used.
 */
final class SmartUpdater
{
    public function __construct(private readonly AgentClient $agent, private readonly Options $options) {}

    /** @return array{followed:int, started:int, pruned:int} */
    public function tick(?Carbon $now = null): array
    {
        $followed = 0;
        foreach (UpdateRun::running()->with('install.website.customer')->get() as $run) {
            $this->follow($run);
            $followed++;
        }

        return ['followed' => $followed, 'started' => $this->startDue($now), 'pruned' => $this->prune()];
    }

    // ------------------------------------------------------------- window

    private function serverOptions(): ?object
    {
        return class_exists(\Rhis\ServerAdmin\Options::class) ? app(\Rhis\ServerAdmin\Options::class) : null;
    }

    /** Whether the window comes from server management (else the own window). */
    public function usesServerWindow(): bool
    {
        return (bool) $this->serverOptions()?->get('window_enabled');
    }

    /**
     * The window that contains $now: [start, end, key, source], or null.
     *
     * @return array{start: Carbon, end: Carbon, key: string, source: string}|null
     */
    public function window(?Carbon $now = null): ?array
    {
        if ($this->usesServerWindow()) {
            $server = $this->serverOptions();
            $at = ($now ?? $server->now())->copy()->timezone($server->timezone());
            $w = $server->windowAt($at);

            return $w ? ['start' => $w[0], 'end' => $w[1], 'key' => 's'.$w[0]->format('Y-m-d H:i'), 'source' => 'server'] : null;
        }
        $at = ($now ?? Carbon::now())->copy()->timezone($this->timezone());
        [$sh, $sm, $eh, $em] = $this->ownTimes();
        foreach ([0, 1] as $back) {
            $start = $at->copy()->subDays($back)->setTime($sh, $sm);
            $end = $start->copy()->setTime($eh, $em);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }
            if ($at->greaterThanOrEqualTo($start) && $at->lessThan($end)) {
                return ['start' => $start, 'end' => $end, 'key' => 'o'.$start->format('Y-m-d H:i'), 'source' => 'own'];
            }
        }

        return null;
    }

    /** When the next window starts (for the screens). */
    public function nextWindow(): ?Carbon
    {
        if ($this->usesServerWindow()) {
            return $this->serverOptions()->nextWindow();
        }
        $now = Carbon::now($this->timezone());
        [$sh, $sm] = $this->ownTimes();
        $start = $now->copy()->setTime($sh, $sm);

        return $start->greaterThan($now) ? $start : $start->addDay();
    }

    /** "03:00 – 04:00" of the window in use. */
    public function windowLabel(): string
    {
        if ($this->usesServerWindow()) {
            $s = $this->serverOptions();

            return $s->get('window_start').' – '.$s->get('window_end');
        }

        return config('wp-smart-updates.window.start').' – '.config('wp-smart-updates.window.end');
    }

    private function timezone(): string
    {
        return (string) (config('server-admin.timezone') ?: config('app.timezone') ?: 'UTC');
    }

    private function ownTimes(): array
    {
        [$sh, $sm] = array_map('intval', explode(':', (string) config('wp-smart-updates.window.start')) + [0, 0]);
        [$eh, $em] = array_map('intval', explode(':', (string) config('wp-smart-updates.window.end')) + [0, 0]);

        return [$sh, $sm, $eh, $em];
    }

    /** An OS update or reboot of server management is still busy. */
    private function serverBusy(): bool
    {
        if (! class_exists(Maintenance::class)) {
            return false;
        }
        try {
            return app(Maintenance::class)->current() !== null;
        } catch (Throwable) {
            return false;
        }
    }

    // ------------------------------------------------------------- starting

    /** Installs whose scheduled update has not run in this window yet, oldest first. */
    public function due(string $windowKey): Collection
    {
        $installs = WordPressInstall::with('website.customer')
            ->whereHas('website', fn ($q) => $q->where('status', 'active'))->get();
        $policies = UpdatePolicy::whereIn('wordpress_install_id', $installs->pluck('id'))->get()->keyBy('wordpress_install_id');
        $running = UpdateRun::running()->pluck('wordpress_install_id')->all();

        return $installs
            ->map(fn (WordPressInstall $i) => [$i, $policies[$i->id] ?? new UpdatePolicy(['wordpress_install_id' => $i->id])])
            ->filter(fn ($p) => $p[1]->effectiveMode() !== 'off' && $p[1]->last_window !== $windowKey && ! in_array($p[0]->id, $running, true))
            ->sortBy(fn ($p) => $p[1]->last_checked_at?->timestamp ?? 0)
            ->map(fn ($p) => $p[0])->values();
    }

    private function startDue(?Carbon $now): int
    {
        $window = $this->window($now);
        if (! $window) {
            return 0;
        }
        $now = ($now ?? Carbon::now())->copy()->timezone($window['start']->getTimezone());
        if ($now->diffInMinutes($window['end'], false) < (int) config('wp-smart-updates.stop_before_end', 15)) {
            return 0;
        }
        // Server management starts its own session at the start of the window: let it go first.
        if ($window['source'] === 'server' && $now->lessThan($window['start']->copy()->addMinutes(5))) {
            return 0;
        }
        if ($this->serverBusy()) {
            return 0;
        }
        $free = max(1, (int) $this->options->get('concurrency')) - UpdateRun::running()->count();
        $started = 0;
        foreach ($free > 0 ? $this->due($window['key'])->take($free) : [] as $install) {
            try {
                $this->start($install, 'schedule', $window['key']);
                $started++;
            } catch (Throwable $e) {
                Log::warning('Smart WordPress update could not start', ['install' => $install->id, 'error' => $e->getMessage()]);
            }
        }

        return $started;
    }

    /** Plugins and themes with an open vulnerability (security mode updates only these). */
    public function securityTargets(WordPressInstall $install): array
    {
        $out = ['plugins' => [], 'themes' => []];
        if (! class_exists(Finding::class)) {
            return $out;
        }
        $rows = Finding::open()->where('wordpress_install_id', $install->id)
            ->where('kind', 'vulnerability')->whereIn('component_type', ['plugin', 'theme'])
            ->get(['component_type', 'component_slug']);
        foreach ($rows as $f) {
            if (preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/', (string) $f->component_slug)) {
                $out[$f->component_type.'s'][] = $f->component_slug;
            }
        }

        return ['plugins' => array_values(array_unique($out['plugins'])), 'themes' => array_values(array_unique($out['themes']))];
    }

    /**
     * Start the smart update of one install. Manual runs update everything
     * ($mode 'all') unless a mode is given; scheduled runs use the policy.
     */
    public function start(WordPressInstall $install, string $trigger = 'manual', ?string $windowKey = null, ?string $mode = null): UpdateRun
    {
        $install->loadMissing('website.customer');
        $policy = UpdatePolicy::for($install);
        $mode ??= $trigger === 'manual' ? 'all' : $policy->effectiveMode();
        if (! in_array($mode, ['all', 'security', 'notify'], true)) {
            throw new RuntimeException(__('Scheduled updates are off for this site.'));
        }
        if (UpdateRun::running()->where('wordpress_install_id', $install->id)->exists()) {
            throw new RuntimeException(__('An update of this site is already running.'));
        }
        $run = UpdateRun::create([
            'wordpress_install_id' => $install->id, 'uid' => UpdateRun::newUid(), 'mode' => $mode, 'trigger' => $trigger,
            'status' => 'running', 'started_at' => now(), 'threshold' => $policy->effectiveThreshold(),
            'started_by' => auth()->user()?->name,
        ]);
        if ($windowKey) {
            $policy->last_window = $windowKey;
        }
        $policy->last_checked_at = now();
        $policy->save();
        try {
            $job = $this->agent->call('wp.smart_update', $install->target() + [
                'run' => $run->uid, 'mode' => $mode, 'security' => $this->securityTargets($install),
                'pages' => $policy->pageList(), 'viewports' => ['desktop', 'mobile'],
                'threshold' => $policy->effectiveThreshold(), 'visual' => $policy->visualEnabled(),
            ], 30)['job'] ?? null;
            $run->update(['job' => $job]);
        } catch (AgentException $e) {
            $run->update(['status' => 'failed', 'reason' => 'error', 'error' => $e->getMessage(), 'finished_at' => now()]);
            $this->notify($run);
        }

        return $run;
    }

    // ------------------------------------------------------------- following

    public function follow(UpdateRun $run): UpdateRun
    {
        if ($run->status !== 'running') {
            return $run;
        }
        $timedOut = $run->started_at && $run->started_at->lt(now()->subMinutes((int) config('wp-smart-updates.job_timeout', 180)));
        if (! $run->job) {
            return $timedOut ? $this->fail($run, __('The update did not start.')) : $run;
        }
        try {
            $state = $this->agent->call('job.status', ['job' => $run->job], 10);
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                return $this->fail($run, __('The update job was lost (the server agent restarted).'));
            }

            return $timedOut ? $this->fail($run, $e->getMessage()) : $run;
        }
        if (($state['status'] ?? 'running') === 'running') {
            return $timedOut ? $this->fail($run, __('The update did not finish in time.')) : $run;
        }

        return $this->finish($run, $state) ?? $run;
    }

    private function fail(UpdateRun $run, string $error): UpdateRun
    {
        $run->update(['status' => 'failed', 'reason' => 'error', 'error' => $error, 'finished_at' => now()]);
        $this->notify($run);

        return $run;
    }

    /** Store a finished job. Returns null when the run was not worth keeping. */
    public function finish(UpdateRun $run, array $state): ?UpdateRun
    {
        $install = $run->install()->with('website.customer')->first();
        $result = $state['result'] ?? [];
        if (($state['status'] ?? '') !== 'succeeded') {
            $result = ($state['error']['details'] ?? []) + ['outcome' => 'failed'];
            $result['errors'] = [$state['error']['message'] ?? __('The update could not be finished.')];
        }
        $outcome = (string) ($result['outcome'] ?? 'failed');
        $status = match ($outcome) {
            'updated' => 'updated',
            'rolled_back' => 'rolled_back',
            'pending' => $run->mode === 'notify' ? 'notified' : 'up_to_date',
            'up_to_date' => 'up_to_date',
            default => 'failed',
        };
        $run->fill([
            'status' => $status,
            'reason' => $status === 'failed' ? 'error' : ($result['reason'] ?? null),
            'pending' => $result['pending'] ?? null,
            'updated' => $result['updated'] ?? null,
            'pages' => $this->pages($result['pages'] ?? []),
            'max_score' => $result['max_score'] ?? null,
            'health' => $result['health'] ?? null,
            'notes' => ['visual' => (bool) ($result['visual'] ?? false), 'visual_errors' => array_values($result['visual_errors'] ?? []),
                'errors' => array_values(array_filter($result['errors'] ?? []))],
            'log' => implode("\n", array_slice($state['log'] ?? [], -300)),
            'error' => $status === 'failed' ? (implode("\n", array_filter($result['errors'] ?? [])) ?: __('The update could not be finished.')) : null,
            'finished_at' => now(),
        ]);
        if ($status === 'updated' && $run->changeCount() === 0) {
            $run->status = 'up_to_date';
        }

        if ($install) {
            if (! empty($result['status']) && is_array($result['status'])) {
                $install->fillFromStatus($result['status']);
            }
            if (in_array($run->status, ['updated', 'rolled_back', 'failed'], true)) {
                $install->last_update_at = now();
                $install->last_update_status = $run->status === 'updated' ? 'succeeded' : $run->status;
                $install->last_update_summary = [
                    'core' => $run->updated['core'] ?? null, 'plugins' => $run->updated['plugins'] ?? [],
                    'themes' => $run->updated['themes'] ?? [], 'errors' => $run->notes['errors'] ?? [],
                    'health' => $run->health, 'smart_update' => $run->uid,
                ];
            }
            $install->save();
        }

        $this->storeImages($run);

        // Nothing happened during a night run: no history row, no mail.
        if ($run->trigger === 'schedule' && $run->status === 'up_to_date') {
            $run->delete();

            return null;
        }
        if ($run->status === 'notified' && $install) {
            $policy = UpdatePolicy::for($install);
            $hash = hash('sha256', json_encode($run->pending));
            if ($run->trigger === 'schedule' && $policy->notified_hash === $hash) {
                $run->delete(); // the same updates were reported before

                return null;
            }
            $policy->notified_hash = $hash;
            $policy->save();
        }
        $run->save();
        $this->notify($run);
        ActivityLog::record('wordpress.smart_update', $install?->label(), ['status' => $run->status, 'mode' => $run->mode,
            'trigger' => $run->trigger], $install?->website?->customer_id);

        return $run;
    }

    /** Keep only what the screens need from the agent's page list. */
    private function pages(array $pages): array
    {
        return collect($pages)->map(fn ($p) => [
            'page' => (string) ($p['page'] ?? '/'), 'viewport' => (string) ($p['viewport'] ?? 'desktop'),
            'score' => isset($p['score']) ? (float) $p['score'] : null, 'noise' => isset($p['noise']) ? (float) $p['noise'] : null,
            'blank' => (bool) ($p['blank'] ?? false), 'restored_score' => isset($p['restored_score']) ? (float) $p['restored_score'] : null,
            'images' => array_filter((array) ($p['images'] ?? []), fn ($n) => is_string($n) && preg_match('/^p[0-9]-(desktop|mobile)-(before|after|diff|restored)\.jpg$/', $n)),
        ])->values()->all();
    }

    /** Copy the screenshots from the agent into the panel's storage, then let the agent forget them. */
    private function storeImages(UpdateRun $run): void
    {
        $names = collect($run->pages ?? [])->flatMap(fn ($p) => array_values($p['images'] ?? []))->unique();
        if ($names->isEmpty()) {
            return;
        }
        $disk = Storage::disk('local');
        $missing = [];
        foreach ($names as $name) {
            try {
                $img = $this->agent->call('wp.smart_image', ['run' => $run->uid, 'name' => $name], 30);
                $disk->put($run->imagePath($name), base64_decode((string) ($img['data'] ?? ''), true) ?: '');
            } catch (AgentException $e) {
                $missing[] = $name;
            }
        }
        if (! $missing) {
            try {
                $this->agent->call('wp.smart_forget', ['run' => $run->uid], 30);
            } catch (AgentException) {
                // the agent removes old screenshots itself after two weeks
            }
        }
    }

    // ------------------------------------------------------------- mail

    public function notify(UpdateRun $run): void
    {
        $install = $run->install()->with('website.customer')->first();
        if (! $install) {
            return;
        }
        $policy = UpdatePolicy::for($install);
        $customer = $install->website?->customer;
        $send = match ($policy->mail) {
            'never' => false,
            'problems' => $run->isProblem() || $run->status === 'notified',
            default => in_array($run->status, ['updated', 'rolled_back', 'failed', 'notified'], true),
        };
        try {
            if ($send && $customer?->email) {
                Mail::to($customer->email)->locale($customer->locale ?: config('app.locale'))->send(new SmartUpdateReport($run, 'client'));
            }
            if ($run->isProblem() && $this->options->get('admin_notify') && $to = $this->options->adminRecipients()) {
                Mail::to($to)->send(new SmartUpdateReport($run, 'admin'));
            }
            $run->forceFill(['mailed_at' => now()])->saveQuietly();
        } catch (Throwable $e) {
            Log::warning('Smart update mail failed', ['run' => $run->uid, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------- history

    private function prune(): int
    {
        if (! Cache::add('wp-smart-updates.prune', 1, 3600)) {
            return 0;
        }
        $old = UpdateRun::where('created_at', '<', now()->subDays(max(7, (int) config('wp-smart-updates.keep_days', 90))))->limit(200)->get();
        $old->each->delete();

        return $old->count();
    }

    /** Numbers for the overview tiles. */
    public function overview(?int $customerId = null): array
    {
        $installs = WordPressInstall::query()->when($customerId, fn ($q) => $q->forCustomer($customerId))->pluck('id');
        $policies = UpdatePolicy::whereIn('wordpress_install_id', $installs)->get()->keyBy('wordpress_install_id');
        $modes = $installs->map(fn ($id) => ($policies[$id] ?? new UpdatePolicy)->effectiveMode())->countBy();
        $recent = UpdateRun::whereIn('wordpress_install_id', $installs)->where('started_at', '>=', now()->subDays(7))->get();

        return [
            'installs' => $installs->count(),
            'automatic' => ($modes['all'] ?? 0) + ($modes['security'] ?? 0),
            'modes' => $modes->all(),
            'updated' => $recent->where('status', 'updated')->count(),
            'rolled_back' => $recent->where('status', 'rolled_back')->count(),
            'failed' => $recent->where('status', 'failed')->count(),
            'running' => $recent->where('status', 'running')->count(),
            'next_window' => $this->nextWindow(),
            'window' => $this->windowLabel(),
            'server_window' => $this->usesServerWindow(),
        ];
    }
}
