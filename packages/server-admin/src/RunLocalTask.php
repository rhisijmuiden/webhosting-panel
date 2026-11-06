<?php

declare(strict_types=1);

namespace Rhis\ServerAdmin;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rhis\ServerAdmin\Models\ServerTask;
use Rhis\ServerAdmin\Models\ServerTaskRun;
use Throwable;

/** A task that runs in the panel itself, started by hand: done by the queue worker, not in the web request. */
class RunLocalTask implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public int $runId, public ?string $phpVersion = null) {}

    public function handle(): void
    {
        $run = ServerTaskRun::find($this->runId);
        if (! $run || ! $run->isRunning()) {
            return;
        }
        try {
            [$status, $summary, $result] = $this->work($run) + [2 => null];
            $run->finish($status, $summary, $result);
        } catch (Throwable $e) {
            report($e);
            $run->appendLog($e->getMessage());
            $run->finish('failed', $e->getMessage());
        }
    }

    private function work(ServerTaskRun $run): array
    {
        return match ($run->task) {
            'panel_cleanup' => app(PanelCleanup::class)->run(ServerTask::synced()->get('panel_cleanup'), $run),
            'health_check' => app(HealthCheck::class)->scheduled($run),
            'php_eol' => app(PhpLifecycle::class)->run($run, $this->phpVersion),
            default => ['failed', 'Unknown task'],
        };
    }

    public function failed(?Throwable $e): void
    {
        ServerTaskRun::find($this->runId)?->finish('failed', $e?->getMessage() ?? __('Failed'));
    }
}
