<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Staging\Console;

use Illuminate\Console\Command;
use Rhis\Subdomains\Staging\Models\Staging;
use Rhis\Subdomains\Staging\Models\StagingPush;
use Rhis\Subdomains\Staging\StagingManager;

/** Finish staging copies whose clone is done and follow running pushes and restores. */
final class StagingTickCommand extends Command
{
    protected $signature = 'wp-staging:tick';

    protected $description = 'Move WordPress staging copies and pushes on';

    public function handle(StagingManager $stagings): int
    {
        $ids = Staging::where('status', 'copying')->pluck('id')
            ->merge(StagingPush::where('status', 'running')->pluck('staging_id'))->unique();
        foreach (Staging::whereIn('id', $ids)->get() as $staging) {
            try {
                $stagings->sync($staging);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
