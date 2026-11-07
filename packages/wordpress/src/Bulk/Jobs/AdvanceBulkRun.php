<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Rhis\WordPress\Bulk\Models\BulkRun;
use Rhis\WordPress\Bulk\Runner;

/**
 * Moves a bulk run forward one short round, then queues the next round (a few seconds
 * later while it waits on agent jobs). Short rounds keep the single queue worker free
 * for other panel work in between. One queued round per run at a time (unique).
 */
class AdvanceBulkRun implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $runId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(Runner $runner): void
    {
        $sync = config('queue.default') === 'sync';
        $deadline = time() + (int) config('wordpress.bulk.slice_seconds', 600);
        $pause = (int) config('wordpress.bulk.poll_seconds', 3);
        do {
            $run = BulkRun::find($this->runId);
            if ($run === null || ! $runner->advance($run)) {
                return;
            }
            $waiting = $runner->isWaiting($run);
            if ($run->refresh()->status !== 'running' && ! $waiting) {
                return; // paused: nothing to do until someone resumes
            }
            if (! $sync) {
                self::dispatch($this->runId)->delay(now()->addSeconds($waiting ? max(1, $pause) : 0));

                return;
            }
            // A sync queue (tests, local) cannot queue ahead: keep going here, the scheduler does the rest.
            if ($waiting && $pause > 0) {
                sleep($pause);
            }
        } while (time() < $deadline);
    }
}
