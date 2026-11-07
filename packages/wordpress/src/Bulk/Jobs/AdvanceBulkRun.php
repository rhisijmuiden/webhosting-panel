<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rhis\WordPress\Bulk\Models\BulkRun;
use Rhis\WordPress\Bulk\Runner;

/** Works on a bulk run for up to a few minutes, then hands over to a fresh copy of itself. */
class AdvanceBulkRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $runId) {}

    public function handle(Runner $runner): void
    {
        $deadline = time() + (int) config('wordpress.bulk.slice_seconds', 600);
        $pause = (int) config('wordpress.bulk.poll_seconds', 3);
        do {
            $run = BulkRun::find($this->runId);
            if ($run === null || ! $runner->advance($run)) {
                return;
            }
            if ($run->refresh()->status !== 'running' && ! $runner->isWaiting($run)) {
                return; // paused: nothing to do until someone resumes
            }
            if ($runner->isWaiting($run) && $pause > 0) {
                sleep($pause);
            }
        } while (time() < $deadline);

        // Hand over to a fresh job (a sync queue would recurse; there the scheduler continues).
        if (config('queue.default') !== 'sync') {
            self::dispatch($this->runId);
        }
    }
}
