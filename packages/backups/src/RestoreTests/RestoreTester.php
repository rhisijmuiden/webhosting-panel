<?php

declare(strict_types=1);

namespace Rhis\Backups\RestoreTests;

use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Rhis\Backups\Mail\RestoreTestReport;
use Rhis\Backups\Models\RestoreTest;
use Throwable;

/**
 * Starts restore tests (agent job backup.verify), follows them, stores the
 * report and mails the administrators when a test fails.
 */
final class RestoreTester
{
    /** A run that is not finished after this long is given up. */
    public const MAX_HOURS = 26;

    /** A scheduled run that was missed is caught up within this many hours. */
    public const CATCH_UP_HOURS = 12;

    /** A run postponed because a backup was busy is tried again after this many minutes. */
    public const RETRY_MINUTES = 15;

    public function __construct(private readonly AgentClient $agent, private readonly RestoreTestOptions $options) {}

    public function running(): ?RestoreTest
    {
        return RestoreTest::where('status', 'running')->latest('id')->first();
    }

    /** Start a run. Throws AgentException when the agent cannot start it. */
    public function start(string $trigger, ?int $userId = null, ?string $target = null, ?Carbon $slot = null): RestoreTest
    {
        $params = $this->options->agentParams($target);
        $result = $this->agent->call('backup.verify', $params, 60);

        return RestoreTest::create([
            'trigger' => $trigger, 'user_id' => $userId, 'target' => $params['target'] ?? null,
            'status' => 'running', 'job_id' => $result['job'] ?? null, 'slot_at' => $slot,
            'started_at' => now(),
        ]);
    }

    /** Is a scheduled run due now? Returns its slot, or null. */
    public function dueSlot(?Carbon $now = null): ?Carbon
    {
        $now = $now ?? now();
        if (! $this->options->enabled() || $this->running()) {
            return null;
        }
        $slot = $this->options->lastSlot($now);
        if ($slot->lt($now->copy()->subHours(self::CATCH_UP_HOURS))) {
            return null;
        }
        $runs = RestoreTest::where('trigger', 'schedule')->where('slot_at', $slot)->get();
        if ($runs->contains(fn (RestoreTest $r) => $r->status !== 'busy')) {
            return null;
        }
        $lastBusy = $runs->max('started_at');
        if ($lastBusy && Carbon::parse($lastBusy)->gt($now->copy()->subMinutes(self::RETRY_MINUTES))) {
            return null;
        }

        return $slot;
    }

    /** Scheduler tick: follow the running test, then start a due one. */
    public function tick(): ?RestoreTest
    {
        foreach (RestoreTest::where('status', 'running')->get() as $run) {
            $this->collect($run);
        }
        $slot = $this->dueSlot();
        if (! $slot) {
            return null;
        }
        try {
            return $this->start('schedule', slot: $slot);
        } catch (AgentException $e) {
            $run = RestoreTest::create([
                'trigger' => 'schedule', 'status' => 'error', 'slot_at' => $slot, 'started_at' => now(),
                'finished_at' => now(), 'error' => $e->getMessage(),
            ]);
            $this->notify($run);

            return $run;
        }
    }

    /** Ask the agent how a running test is doing and store the result when it is done. */
    public function collect(RestoreTest $run): RestoreTest
    {
        if (! $run->isRunning()) {
            return $run;
        }
        $state = null;
        try {
            $state = $run->job_id ? $this->agent->call('job.status', ['job' => $run->job_id], 15) : null;
        } catch (AgentException $e) {
            if ($e->errorCode !== 'not_found') {
                // Agent not reachable right now: try again on the next tick.
                return $this->giveUpWhenTooOld($run, $e->getMessage());
            }
        }

        if (($state['status'] ?? null) === 'running') {
            return $this->giveUpWhenTooOld($run, __('The restore test did not finish within :h hours.', ['h' => self::MAX_HOURS]));
        }
        if (($state['status'] ?? null) === 'succeeded' && is_array($state['result'] ?? null) && isset($state['result']['targets'])) {
            return $this->finish($run, $state['result']);
        }
        if (($state['status'] ?? null) === 'failed') {
            $error = $state['error'] ?? [];
            if (($error['code'] ?? '') === 'conflict') {
                // Another backup job held the lock: try again shortly (scheduled runs) or tell the admin.
                $run->update(['status' => 'busy', 'finished_at' => now(),
                    'error' => __('A backup or restore was running. The restore test is tried again shortly.')]);

                return $run;
            }
            $run->update(['status' => 'error', 'finished_at' => now(), 'error' => $error['message'] ?? __('The restore test could not run.')]);
            $this->notify($run);

            return $run;
        }

        // Job unknown (for example the agent restarted): use the last report when it is from this run.
        try {
            $report = $this->agent->call('backup.verify_last', [], 15)['report'] ?? null;
        } catch (AgentException) {
            $report = null;
        }
        if (is_array($report) && isset($report['started']) && Carbon::parse($report['started'])->gte($run->started_at->copy()->subMinute())) {
            return $this->finish($run, $report);
        }

        return $this->giveUpWhenTooOld($run, __('The restore test was interrupted.'), force: true);
    }

    public function finish(RestoreTest $run, array $report): RestoreTest
    {
        $status = in_array($report['status'] ?? null, ['ok', 'warning', 'failed'], true) ? $report['status'] : 'failed';
        $items = 0;
        $problems = 0;
        foreach ($report['targets'] ?? [] as $t) {
            foreach ($t['items'] ?? [] as $item) {
                $items++;
                $problems += in_array($item['status'] ?? '', ['failed', 'warning', 'skipped'], true) ? 1 : 0;
            }
            $problems += count($t['coverage']['missing'] ?? []) + count($t['coverage']['stale'] ?? []);
            $problems += isset($t['error']) && empty($t['items']) ? 1 : 0;
        }
        $run->update([
            'status' => $status, 'report' => $report, 'tested' => $items, 'problems' => $problems,
            'finished_at' => isset($report['finished']) ? Carbon::parse($report['finished']) : now(),
        ]);
        if ($run->isProblem()) {
            ActivityLog::record('backup.restore_test_failed', $run->target, ['test' => $run->id, 'problems' => $problems]);
        }
        $this->notify($run);

        return $run;
    }

    private function giveUpWhenTooOld(RestoreTest $run, string $message, bool $force = false): RestoreTest
    {
        if ($force || $run->started_at->lt(now()->subHours(self::MAX_HOURS))) {
            $run->update(['status' => 'error', 'finished_at' => now(), 'error' => $message]);
            $this->notify($run);
        }

        return $run;
    }

    /** Mail the administrators, depending on the notify setting. A first pass after a failure is mailed too. */
    public function notify(RestoreTest $run): bool
    {
        $mode = $this->options->get('notify');
        if ($mode === 'never' || $run->notified_at) {
            return false;
        }
        $previous = RestoreTest::whereIn('status', RestoreTest::FINISHED)->where('id', '<', $run->id)->latest('id')->first();
        $recovered = $run->status === 'ok' && $previous?->isProblem();
        $send = match ($mode) {
            'always' => true,
            'warnings' => $run->status !== 'ok' || $recovered,
            default => $run->isProblem() || $recovered,
        };
        $to = $this->options->recipients();
        if (! $send || ! $to) {
            return false;
        }
        try {
            Mail::to($to)->send(new RestoreTestReport($run, (bool) $recovered, gethostname() ?: (string) config('app.url')));
            $run->update(['notified_at' => now()]);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
