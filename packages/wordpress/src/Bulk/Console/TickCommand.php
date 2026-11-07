<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk\Console;

use Illuminate\Console\Command;
use Rhis\WordPress\Bulk\Models\BulkRun;
use Rhis\WordPress\Bulk\Runner;

/** Every minute: move runs whose queue worker went away (restart, deploy). */
final class TickCommand extends Command
{
    protected $signature = 'whp:wp-bulk:tick';

    protected $description = 'Continue WordPress bulk runs that are waiting';

    public function handle(Runner $runner): int
    {
        // Paused and cancelled runs can still have busy sites to follow.
        $runs = BulkRun::whereNull('finished_at')->whereIn('status', ['running', 'paused', 'cancelled'])->get();
        foreach ($runs as $run) {
            // Only runs no worker touched for a while; a busy worker keeps updating items.
            $last = $run->items()->reorder()->max('updated_at');
            if ($last !== null && now()->diffInSeconds($last, true) < 90) {
                continue;
            }
            $runner->advance($run);
        }

        return self::SUCCESS;
    }
}
