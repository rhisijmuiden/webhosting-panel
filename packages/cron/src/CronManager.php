<?php

declare(strict_types=1);

namespace Rhis\Cron;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Rhis\Cron\Mail\CronJobFailed;
use Rhis\Cron\Models\CronJob;
use Rhis\Cron\Models\CronRun;
use Throwable;

/** Everything the panel asks the agent about cron jobs, and the run history. */
final class CronManager
{
    private const CURSOR = 'cron.runs.cursor';

    public function __construct(private readonly AgentClient $agent) {}

    /** How many cron jobs this website may have (package max_cronjobs; null there = the panel cap). */
    public static function limit(Website $website): int
    {
        $max = $website->package?->max_cronjobs;
        $cap = (int) config('cron.max_per_website', 50);

        return $max === null ? $cap : min((int) $max, $cap);
    }

    public static function allowed(Website $website): bool
    {
        return self::limit($website) > 0;
    }

    public function payload(CronJob $job): array
    {
        return [
            'id' => $job->uid, 'domain' => $job->website->domain, 'name' => $job->name, 'type' => $job->type,
            'command' => $job->command, 'args' => (string) $job->args, 'schedule' => $job->schedule,
            'timezone' => (string) config('app.timezone'), 'timeout' => $job->timeout,
            'enabled' => $job->enabled && $job->website->status !== 'suspended',
        ];
    }

    /** Write the job to the server. Throws AgentException. */
    public function apply(CronJob $job): array
    {
        $job->loadMissing('website');
        try {
            $out = $this->agent->call('cron.apply', $this->payload($job), 60);
        } catch (AgentException $e) {
            $job->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
        if ($job->last_error !== null) {
            $job->update(['last_error' => null]);
        }

        return $out;
    }

    public function reapply(CronJob $job): void
    {
        try {
            $this->apply($job);
        } catch (AgentException $e) {
            Log::warning('cron.apply failed', ['job' => $job->uid, 'error' => $e->getMessage()]);
        }
    }

    /** Live status of many jobs: [uid => [running, next_run ...]]. */
    public function statuses(Collection $jobs): array
    {
        if ($jobs->isEmpty()) {
            return [];
        }
        try {
            return $this->agent->call('cron.list', ['ids' => $jobs->pluck('uid')->values()->all()], 30)['jobs'] ?? [];
        } catch (AgentException) {
            return [];
        }
    }

    public function status(CronJob $job): ?array
    {
        return $this->statuses(collect([$job]))[$job->uid] ?? null;
    }

    public function runNow(CronJob $job): array
    {
        return $this->agent->call('cron.run', ['id' => $job->uid], 30);
    }

    public function stop(CronJob $job): array
    {
        return $this->agent->call('cron.stop', ['id' => $job->uid], 90);
    }

    public function remove(CronJob $job): void
    {
        $this->agent->call('cron.remove', ['id' => $job->uid], 90);
        $job->delete();
    }

    public function suspend(Website $website, bool $suspended): void
    {
        if (! CronJob::withoutGlobalScopes()->where('website_id', $website->id)->exists()) {
            return;
        }
        try {
            $this->agent->call('cron.suspend', ['domain' => $website->domain, 'suspended' => $suspended], 120);
        } catch (AgentException $e) {
            Log::warning('cron.suspend failed', ['domain' => $website->domain, 'error' => $e->getMessage()]);
        }
    }

    // ------------------------------------------------------------- runs

    /**
     * Fetch finished runs from the server and keep them; mail on failures.
     * $jobs: only these (a job page that waits for its run), otherwise all.
     * Returns the number of new runs.
     */
    public function syncRuns(?Collection $jobs = null): int
    {
        $params = ['since' => 0];
        if ($jobs !== null) {
            if ($jobs->isEmpty()) {
                return 0;
            }
            $params['ids'] = $jobs->pluck('uid')->values()->all();
            $last = CronRun::whereIn('cron_job_id', $jobs->pluck('id'))->max('finished_at');
            $params['since'] = $last ? max(0, Carbon::parse($last)->timestamp - 300) : 0;
        } else {
            // A little overlap: runs are kept by their invocation id, so none is counted twice.
            $params['since'] = max(0, (float) Cache::get(self::CURSOR, 0) - 300);
        }
        $out = $this->agent->call('cron.runs', $params, 60);
        $byUid = CronJob::withoutGlobalScopes()->with('website.customer')
            ->whereIn('uid', collect($out['runs'] ?? [])->pluck('id')->unique()->values())->get()->keyBy('uid');
        $new = 0;
        $maxFinished = (float) Cache::get(self::CURSOR, 0);
        foreach ($out['runs'] ?? [] as $r) {
            $maxFinished = max($maxFinished, (float) ($r['finished'] ?? 0));
            $job = $byUid[$r['id'] ?? ''] ?? null;
            if (! $job || empty($r['invocation'])) {
                continue;
            }
            $run = CronRun::firstOrNew(['invocation' => (string) $r['invocation']]);
            if ($run->exists) {
                continue;
            }
            $run->fill([
                'cron_job_id' => $job->id,
                'status' => array_key_exists($r['status'] ?? '', CronRun::STATUSES) ? $r['status'] : 'failed',
                'exit_status' => $r['exit_status'] ?? null, 'signal' => isset($r['signal']) ? substr((string) $r['signal'], 0, 20) : null,
                'started_at' => isset($r['started']) ? Carbon::createFromTimestamp((int) $r['started']) : null,
                'finished_at' => Carbon::createFromTimestamp((int) floor((float) $r['finished'])),
                'duration_ms' => isset($r['duration_ms']) ? (int) $r['duration_ms'] : null,
                'output' => mb_scrub((string) ($r['output'] ?? ''), 'UTF-8'), 'truncated' => (bool) ($r['truncated'] ?? false),
            ])->save();
            $new++;
            $this->recorded($job, $run);
        }
        if ($jobs === null) {
            Cache::forever(self::CURSOR, $maxFinished);
        }

        return $new;
    }

    /** Bring the job's summary up to date and mail when it failed. */
    private function recorded(CronJob $job, CronRun $run): void
    {
        if ($job->last_run_at && $run->finished_at && $run->finished_at->lt($job->last_run_at)) {
            return; // an older run that arrived late
        }
        $failed = $run->failed();
        $job->fill([
            'last_run_at' => $run->finished_at, 'last_status' => $run->status, 'last_duration_ms' => $run->duration_ms,
            'failures' => $failed ? $job->failures + 1 : ($run->status === 'success' ? 0 : $job->failures),
        ]);
        if ($failed && $this->shouldNotify($job)) {
            $this->notify($job, $run);
            $job->notified_at = now();
        }
        if ($run->status === 'success') {
            $job->notified_at = null;
        }
        $job->save();
        $this->prune($job);
    }

    /** First failure after a good run, then again after remind_hours while it keeps failing. */
    private function shouldNotify(CronJob $job): bool
    {
        if (! $job->notify_failure) {
            return false;
        }

        return $job->notified_at === null || $job->notified_at->lt(now()->subHours(max(1, (int) config('cron.remind_hours', 24))));
    }

    public static function recipient(CronJob $job): ?string
    {
        $address = $job->notify_email ?: $job->website?->customer?->email;

        return filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }

    private function notify(CronJob $job, CronRun $run): void
    {
        $to = self::recipient($job);
        if (! $to) {
            return;
        }
        $customer = $job->website?->customer;
        try {
            Mail::to($to)->locale($customer?->locale ?: config('app.locale'))->send(new CronJobFailed($job, $run));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function prune(CronJob $job): void
    {
        $keep = max(5, (int) config('cron.keep_runs', 25));
        $ids = CronRun::where('cron_job_id', $job->id)->orderByDesc('finished_at')->orderByDesc('id')->skip($keep)->take(1000)->pluck('id');
        if ($ids->isNotEmpty()) {
            CronRun::whereIn('id', $ids)->delete();
        }
    }
}
