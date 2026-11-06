<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Rhis\ServerAdmin\Models\ServerTaskRun;
use RuntimeException;
use Throwable;

/**
 * Maintenance sessions: install updates, reboot when needed and check every
 * website and service afterwards. A session is a ServerTaskRun (task
 * "maintenance") whose result holds the state; the scheduler moves it on
 * every minute (whp:server:tick), also across the reboot.
 *
 * Sources: window (the nightly maintenance window), critical (a CVE with a
 * CVSS score at or above the threshold), manual (Reboot in the panel).
 */
final class Maintenance
{
    private const MAX_HOURS = 6;

    private const SETTLE_SECONDS = 120;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Options $options,
        private readonly HealthCheck $health,
        private readonly Notifier $notifier,
    ) {}

    public function current(): ?ServerTaskRun
    {
        return ServerTaskRun::running()->where('task', 'maintenance')->latest('id')->first();
    }

    public function tick(?Carbon $now = null): void
    {
        $now ??= $this->options->now();
        $this->syncAutoUpdates();
        if ($session = $this->current()) {
            $this->advance($session);

            return;
        }
        $window = $this->options->windowAt($now);
        if (! $window) {
            return;
        }
        $key = $window[0]->format('Y-m-d H:i');
        if ($this->options->state('last_window') === $key) {
            return;
        }
        $this->options->setState('last_window', $key);
        // Started late (panel was down)? Only when there is still room in the window.
        if ($now->diffInMinutes($window[1], false) >= 15) {
            $this->advance($this->start('window'));
        }
    }

    /** Tell the server once who installs updates: the panel's window, or Debian's own daily run. */
    private function syncAutoUpdates(): void
    {
        $mode = $this->options->get('window_enabled') ? 'window' : 'daily';
        if ($this->options->state('auto_updates') === $mode) {
            return;
        }
        try {
            $this->agent->call('server.auto_updates', ['mode' => $mode], 30);
            $this->options->setState('auto_updates', $mode);
        } catch (AgentException) {
            // next minute
        }
    }

    public function start(string $source, ?int $userId = null, array $extra = []): ServerTaskRun
    {
        $phase = $source === 'manual' ? 'reboot_precheck' : 'precheck';
        $run = ServerTaskRun::create([
            'task' => 'maintenance', 'trigger' => $source, 'status' => 'running', 'user_id' => $userId, 'started_at' => now(),
            'result' => ['phase' => $phase, 'source' => $source] + $extra,
        ]);
        $run->appendLog(match ($source) {
            'window' => __('Maintenance window started'),
            'critical' => __('Critical security update found'),
            default => __('Reboot requested'),
        });

        return $run;
    }

    public function manualReboot(int $userId): ServerTaskRun
    {
        if ($this->current()) {
            throw new RuntimeException(__('Maintenance is already running. Wait until it is done.'));
        }
        ActivityLog::record('server.reboot_requested', gethostname() ?: null);

        return $this->start('manual', $userId);
    }

    public function cancel(ServerTaskRun $run, int $userId): void
    {
        $state = $run->result ?? [];
        if (($state['phase'] ?? '') === 'rebooting') {
            $this->agent->call('server.reboot_cancel', [], 30);
        }
        $run->appendLog(__('Cancelled by :user', ['user' => \App\Models\User::find($userId)?->name ?? '?']));
        $run->finish('failed', __('Cancelled'));
        ActivityLog::record('server.maintenance_cancelled', (string) $run->id);
    }

    /** Move a session on as far as it can go right now. */
    public function advance(ServerTaskRun $run): void
    {
        for ($i = 0; $i < 6 && $run->isRunning(); $i++) {
            if ($run->started_at->lt(now()->subHours(self::MAX_HOURS))) {
                $run->appendLog(__('Stopped: took longer than :h hours', ['h' => self::MAX_HOURS]));
                $run->finish('failed', __('Took too long'));
                $this->notifier->problem(__('Maintenance did not finish'), [__('Check the log in server management.')]);

                return;
            }
            try {
                if (! $this->step($run)) {
                    return;
                }
            } catch (AgentException $e) {
                // The agent is gone while the server reboots: fine, try again next minute.
                if (($run->result['phase'] ?? '') === 'rebooting' || $e->errorCode === 'unreachable') {
                    return;
                }
                $run->appendLog($e->getMessage());
                $run->finish('failed', $e->getMessage());
                $this->notifier->problem(__('Maintenance failed'), [$e->getMessage()]);

                return;
            }
        }
    }

    /** One state change. Returns false while waiting. */
    private function step(ServerTaskRun $run): bool
    {
        $s = $run->result ?? [];
        $source = $s['source'] ?? 'window';

        switch ($s['phase'] ?? 'precheck') {
            case 'precheck':
                $pre = $this->health->run();
                $run->appendLog(__('Before: :line', ['line' => HealthCheck::line($pre)]));
                $s['pre'] = $this->compact($pre);
                if ($source === 'critical') {
                    $this->notifier->warning(__('Critical security update: installing now'), $this->cveLines($s['cves'] ?? []),
                        $this->criticalRebootPlan());
                }
                $mode = $source === 'critical' ? 'security' : (string) $this->options->get('window_install');
                $upgrade = app(Runner::class)->startAgent('upgrade', 'server.upgrade', ['mode' => $mode], $source, $run->user_id);
                $run->appendLog(__('Installing updates (:mode)', ['mode' => $mode]));
                $s['upgrade_run'] = $upgrade->id;
                $s['phase'] = 'upgrading';
                break;

            case 'upgrading':
                $upgrade = ServerTaskRun::find($s['upgrade_run'] ?? 0);
                if ($upgrade?->isRunning()) {
                    app(Runner::class)->poll($upgrade);
                    if ($upgrade->isRunning()) {
                        return false;
                    }
                }
                if (! $upgrade || $upgrade->status !== 'succeeded') {
                    $s['upgrade_failed'] = $upgrade?->summary ?? __('Unknown error');
                    $run->appendLog(__('Installing updates failed: :error', ['error' => $s['upgrade_failed']]));
                    $s['phase'] = 'postcheck';
                    break;
                }
                $run->appendLog($upgrade->summary ?? __('Updates installed.'));
                $status = $this->agent->call('server.status', [], 60);
                $s['reasons'] = $status['reboot_reasons'] ?? [];
                if (! $s['reasons']) {
                    $run->appendLog(__('No reboot needed'));
                    $s['phase'] = 'postcheck';
                    break;
                }
                $run->appendLog(__('Reboot needed: :why', ['why' => $this->reasonLine($s['reasons'])]));
                $delay = $this->rebootDelay($source);
                if ($delay === null) {
                    $run->appendLog(__('The reboot waits for the next maintenance window'));
                    $s['reboot_waiting'] = true;
                    $s['phase'] = 'postcheck';
                    break;
                }
                $this->agent->call('server.reboot', ['delay' => $delay, 'reason' => $source], 30);
                $s['requested_at'] = now()->getTimestamp();
                $s['reboot_delay'] = $delay;
                $s['phase'] = 'rebooting';
                $run->appendLog(__('Rebooting in :n seconds', ['n' => $delay]));
                if ($source === 'critical') {
                    $this->notifier->warning(__('The server reboots in :n minutes', ['n' => (int) ceil($delay / 60)]),
                        [__('Reason: :why', ['why' => $this->reasonLine($s['reasons'])])],
                        __('The security updates are installed. A reboot finishes them; afterwards every website is checked.'));
                } elseif ($this->options->get('mail_mode') !== 'problems') {
                    $this->notifier->info(__('The server reboots for updates'), [__('Reason: :why', ['why' => $this->reasonLine($s['reasons'])])]);
                }
                break;

            case 'reboot_precheck':
                $pre = $this->health->run();
                $run->appendLog(__('Before: :line', ['line' => HealthCheck::line($pre)]));
                $s['pre'] = $this->compact($pre);
                $this->agent->call('server.reboot', ['delay' => 10, 'reason' => 'manual'], 30);
                $s['requested_at'] = now()->getTimestamp();
                $s['reboot_delay'] = 10;
                $s['phase'] = 'rebooting';
                $run->appendLog(__('Rebooting in :n seconds', ['n' => 10]));
                break;

            case 'rebooting':
                $status = $this->agent->call('server.status', [], 15);
                $boot = (int) ($status['boot_time'] ?? 0);
                if ($boot > (int) $s['requested_at']) {
                    if (now()->getTimestamp() - $boot < self::SETTLE_SECONDS) {
                        return false;
                    }
                    $s['rebooted'] = true;
                    $s['kernel'] = $status['kernel'] ?? null;
                    $run->appendLog(__('The server is back (kernel :kernel)', ['kernel' => $s['kernel'] ?? '?']));
                    $s['phase'] = 'postcheck';
                    break;
                }
                if (now()->getTimestamp() - (int) $s['requested_at'] > 1800 + (int) ($s['reboot_delay'] ?? 0)) {
                    $s['reboot_failed'] = true;
                    $run->appendLog(__('The server did not reboot'));
                    $s['phase'] = 'postcheck';
                    break;
                }

                return false;

            case 'postcheck':
            case 'postcheck_retry':
                if ($s['phase'] === 'postcheck_retry' && now()->getTimestamp() < (int) ($s['retry_at'] ?? 0)) {
                    return false;
                }
                $post = $this->health->run();
                $run->appendLog(__('After: :line', ['line' => HealthCheck::line($post)]));
                $s['post'] = $this->compact($post);
                $reg = HealthCheck::regressions($s['pre'] ?? null, $post);
                if (($reg['sites'] || $reg['services']) && $s['phase'] === 'postcheck') {
                    foreach ($reg['services'] as $service) {
                        try {
                            $this->agent->call('service.restart', ['service' => $service], 120);
                            $run->appendLog(__('Restarted :service', ['service' => $service]));
                        } catch (AgentException $e) {
                            $run->appendLog(__('Could not restart :service: :error', ['service' => $service, 'error' => $e->getMessage()]));
                        }
                    }
                    $s['phase'] = 'postcheck_retry';
                    $s['retry_at'] = now()->getTimestamp() + 60;
                    $run->result = $s;
                    $run->save();

                    return false;
                }
                $s['regressions'] = $reg;
                $run->result = $s;
                $this->complete($run, $s, $source);

                return false;
        }

        $run->result = $s;
        $run->save();

        return true;
    }

    private function complete(ServerTaskRun $run, array $s, string $source): void
    {
        $reg = $s['regressions'] ?? ['sites' => [], 'services' => []];
        $problems = $reg['sites'] || $reg['services'] || ! empty($s['upgrade_failed']) || ! empty($s['reboot_failed']);
        $lines = [];
        if (! empty($s['upgrade_failed'])) {
            $lines[] = __('Installing updates failed: :error', ['error' => $s['upgrade_failed']]);
        }
        if (! empty($s['rebooted'])) {
            $lines[] = __('The server rebooted (kernel :kernel)', ['kernel' => $s['kernel'] ?? '?']);
        } elseif (! empty($s['reboot_failed'])) {
            $lines[] = __('The server did not reboot');
        } elseif (! empty($s['reboot_waiting'])) {
            $lines[] = __('A reboot is needed and waits for the next maintenance window');
        }
        if (isset($s['post'])) {
            $lines[] = HealthCheck::line($s['post']);
        }
        foreach ($reg['sites'] as $d) {
            $lines[] = __('Website :domain does not answer since the maintenance', ['domain' => $d]);
        }
        foreach ($reg['services'] as $svc) {
            $lines[] = __('Service :service is not running since the maintenance', ['service' => $svc]);
        }
        $summary = $problems ? __('Done with problems') : (! empty($s['rebooted']) ? __('Updated and rebooted, everything works') : __('Updated, everything works'));
        $run->finish($problems ? 'failed' : 'succeeded', $summary, $s);
        $run->appendLog($summary);

        if ($problems) {
            $this->notifier->problem(__('Maintenance: something needs attention'), $lines);
        } elseif ($source === 'critical'
            || $this->options->get('mail_mode') === 'always'
            || ($this->options->get('mail_mode') === 'reboots' && ! empty($s['rebooted']) && $source !== 'manual')) {
            $this->notifier->info(__('Maintenance done: everything works'), $lines);
        }
    }

    /** Seconds until the reboot, or null when it has to wait for the window. */
    private function rebootDelay(string $source): ?int
    {
        $inWindow = $this->options->windowAt($this->options->now()) !== null;
        if ($source === 'critical') {
            if ($this->options->get('critical_reboot') === 'soon') {
                return max(60, (int) $this->options->get('critical_reboot_delay') * 60);
            }

            return $inWindow ? 60 : null;
        }

        return $this->options->get('window_reboot') && $inWindow ? 60 : null;
    }

    private function criticalRebootPlan(): string
    {
        return $this->options->get('critical_reboot') === 'soon'
            ? __('If the update needs a reboot, the server reboots :n minutes after installing, and every website is checked afterwards.', ['n' => max(1, (int) $this->options->get('critical_reboot_delay'))])
            : __('If the update needs a reboot, that happens in the next maintenance window.');
    }

    /** After a security check: start patching when it found a critical CVE that was not handled yet. */
    public function onRunFinished(ServerTaskRun $run): void
    {
        if ($run->task !== 'security_check' || $run->status !== 'succeeded') {
            return;
        }
        $r = $run->result ?? [];
        $this->options->setState('last_scan', [
            'at' => now()->getTimestamp(), 'max_score' => $r['max_score'] ?? null, 'critical' => (bool) ($r['critical'] ?? false),
            'packages' => array_slice($r['packages'] ?? [], 0, 200),
        ]);
        if (empty($r['critical']) || ! $this->options->get('critical_enabled') || $this->current()) {
            return;
        }
        $threshold = (float) $this->options->get('critical_threshold');
        $cves = [];
        foreach ($r['packages'] ?? [] as $p) {
            foreach ($p['cves'] ?? [] as $c) {
                if (($c['score'] ?? 0) >= $threshold) {
                    $cves[$c['id']] = ['id' => $c['id'], 'score' => $c['score'], 'package' => $p['name']];
                }
            }
        }
        $handled = (array) $this->options->state('critical_handled', []);
        $new = array_diff_key($cves, array_flip($handled));
        if (! $new) {
            return;
        }
        $this->options->setState('critical_handled', array_slice(array_merge($handled, array_keys($new)), -500));
        ActivityLog::record('server.critical_patch', implode(', ', array_keys($new)));
        $this->advance($this->start('critical', null, ['cves' => array_values($new)]));
    }

    private function cveLines(array $cves): array
    {
        return array_map(fn ($c) => "{$c['id']} (CVSS {$c['score']}) ".__('in :package', ['package' => $c['package']]), array_slice($cves, 0, 20));
    }

    private function reasonLine(array $reasons): string
    {
        return implode('; ', array_map(fn ($r) => match ($r['kind'] ?? '') {
            'kernel' => __('new kernel :v', ['v' => $r['detail'] ?? '']),
            'libraries' => __('services use replaced libraries (:list)', ['list' => $r['detail'] ?? '']),
            default => __('updated packages (:list)', ['list' => $r['detail'] ?? '']),
        }, $reasons));
    }

    private function compact(array $health): array
    {
        return [
            'sites' => count($health['sites'] ?? []),
            'sites_down' => array_values($health['sites_down'] ?? []),
            'services_down' => array_values($health['services_down'] ?? []),
            'checked_at' => $health['checked_at'] ?? now()->getTimestamp(),
        ];
    }

    /** Lines for the "what happens next" box. */
    public function describe(): array
    {
        try {
            $next = $this->options->nextWindow();
        } catch (Throwable) {
            $next = null;
        }

        return ['next_window' => $next];
    }
}
