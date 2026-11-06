<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin\Console;

use Illuminate\Console\Command;
use Rhis\ServerAdmin\Maintenance;
use Rhis\ServerAdmin\Models\ServerTask;
use Rhis\ServerAdmin\Models\ServerTaskRun;
use Rhis\ServerAdmin\Runner;
use Throwable;

/** Every minute: follow running jobs, start due tasks, move the maintenance session on. */
class TickCommand extends Command
{
    protected $signature = 'whp:server:tick';

    protected $description = 'Run due server maintenance tasks and the maintenance window';

    public function handle(Runner $runner, Maintenance $maintenance): int
    {
        $runner->pollRunning();

        foreach (ServerTask::synced() as $task) {
            if (! $task->enabled || ! $task->next_run_at || $task->next_run_at->isFuture()) {
                continue;
            }
            $task->update(['next_run_at' => $task->nextRun()]);
            if (ServerTaskRun::running()->where('task', $task->key)->exists()) {
                continue;
            }
            // Tasks skip their turn while maintenance (updates, reboot) is busy:
            // no apt lock fights and no "website down" mails during a reboot.
            if ($maintenance->current() || ServerTaskRun::running()->whereIn('task', ['upgrade', 'php_install', 'php_remove'])->exists()) {
                continue;
            }
            try {
                $runner->runTask($task);
            } catch (Throwable $e) {
                report($e);
            }
        }

        try {
            $maintenance->tick();
        } catch (Throwable $e) {
            report($e);
        }

        return self::SUCCESS;
    }
}
