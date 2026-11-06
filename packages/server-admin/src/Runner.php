<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Rhis\ServerAdmin\Models\ServerTask;
use Rhis\ServerAdmin\Models\ServerTaskRun;
use Throwable;

/** Starts tasks (agent jobs or work in the panel itself) and follows them until they finish. */
final class Runner
{
    public function __construct(private readonly AgentClient $agent, private readonly Options $options) {}

    public function runTask(ServerTask $task, string $trigger = 'schedule', ?int $userId = null): ServerTaskRun
    {
        return match ($task->key) {
            'security_check' => $this->startAgent('security_check', 'server.security_scan', [
                'threshold' => (float) $this->options->get('critical_threshold'),
                'nvd_api_key' => (string) ($this->options->get('nvd_api_key') ?? ''),
            ], $trigger, $userId),
            'cleanup' => $this->startAgent('cleanup', 'server.cleanup', [
                'apt' => (bool) $task->option('apt', true),
                'journal_days' => (int) $task->option('journal_days', 30),
                'site_tmp_days' => (int) $task->option('site_tmp_days', 7),
                'site_sessions_days' => (int) $task->option('site_sessions_days', 2),
                'jobs_days' => (int) $task->option('jobs_days', 30),
            ], $trigger, $userId),
            'db_optimize' => $this->startAgent('db_optimize', 'server.db_optimize', [
                'min_free_mb' => (int) $task->option('min_free_mb', 50),
                'analyze' => (bool) $task->option('analyze', true),
            ], $trigger, $userId),
            'panel_cleanup', 'health_check', 'php_eol' => $this->queueLocal($task->key, $trigger, $userId, null, $trigger !== 'manual'),
        };
    }

    public function startAgent(string $task, string $action, array $params, string $trigger = 'manual', ?int $userId = null): ServerTaskRun
    {
        $run = ServerTaskRun::create(['task' => $task, 'trigger' => $trigger, 'status' => 'running', 'user_id' => $userId,
            'params' => collect($params)->except('nvd_api_key')->all(), 'started_at' => now()]);
        try {
            $data = $this->agent->call($action, $params, 60);
            $run->update(['agent_job' => $data['job'] ?? null]);
            if (! ($data['job'] ?? null)) {
                $run->finish('succeeded', Tasks::summary($task, $data, null), $data);
            }
        } catch (AgentException $e) {
            $run->appendLog($e->getMessage());
            $run->finish('failed', $e->getMessage());
            $this->finished($run);
        }

        return $run;
    }

    /**
     * A task that runs in the panel itself. By hand it goes to the queue worker
     * (the screen follows the run); from the scheduler it runs right here.
     */
    public function queueLocal(string $task, string $trigger, ?int $userId, ?string $phpVersion = null, bool $now = false): ServerTaskRun
    {
        $run = ServerTaskRun::create(['task' => $task, 'trigger' => $trigger, 'status' => 'running', 'user_id' => $userId, 'started_at' => now()]);
        $job = new RunLocalTask($run->id, $phpVersion);
        $now ? $job->handle() : dispatch($job);

        return $run->fresh();
    }

    /** Follow every agent job that is still running. */
    public function pollRunning(): void
    {
        foreach (ServerTaskRun::running()->whereNotNull('agent_job')->get() as $run) {
            $this->poll($run);
        }
    }

    public function poll(ServerTaskRun $run): ServerTaskRun
    {
        if (! $run->isRunning() || ! $run->agent_job) {
            return $run;
        }
        try {
            $job = $this->agent->call('job.status', ['job' => $run->agent_job], 10);
        } catch (AgentException $e) {
            if ($e->errorCode !== 'not_found') {
                return $run; // agent busy or restarting: try again on the next tick
            }

            return $this->recover($run);
        }

        $lines = (array) ($job['log'] ?? []);
        foreach ($lines as $line) {
            if (! $run->apt_run && preg_match('/^Run id ([0-9a-f]{32})$/', (string) $line, $m)) {
                $run->apt_run = $m[1];
            }
        }
        $run->log = implode("\n", $lines);
        if (($job['status'] ?? 'running') === 'running') {
            $run->save();

            return $run;
        }
        $status = $job['status'] === 'succeeded' ? 'succeeded' : 'failed';
        $run->finish($status, Tasks::summary($run->task, $job['result'] ?? null, $job['error'] ?? null), $job['result'] ?? null);
        $this->finished($run);

        return $run;
    }

    /** The agent restarted (an update can do that) and forgot the job: read the package run itself. */
    private function recover(ServerTaskRun $run): ServerTaskRun
    {
        if ($run->apt_run) {
            try {
                $st = $this->agent->call('server.apt_status', ['run' => $run->apt_run], 10);
                if (($st['status'] ?? '') === 'running') {
                    $run->update(['log' => implode("\n", (array) ($st['log'] ?? []))]);

                    return $run;
                }
                $run->log = implode("\n", (array) ($st['log'] ?? []));
                $run->finish($st['status'] === 'succeeded' ? 'succeeded' : 'failed',
                    $st['status'] === 'succeeded' ? Tasks::summary($run->task, [], null) : __('Exit code :code', ['code' => $st['exit_code'] ?? '?']));
                $this->finished($run);

                return $run;
            } catch (AgentException) {
            }
        }
        $run->appendLog(__('The agent restarted and lost track of this run.'));
        $run->finish('failed', __('The agent restarted and lost track of this run.'));
        $this->finished($run);

        return $run;
    }

    private function finished(ServerTaskRun $run): void
    {
        try {
            app(Maintenance::class)->onRunFinished($run);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
