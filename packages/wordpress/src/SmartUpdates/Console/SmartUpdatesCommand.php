<?php

declare(strict_types=1);

namespace Rhis\WordPress\SmartUpdates\Console;

use Illuminate\Console\Command;
use Rhis\WordPress\SmartUpdates\SmartUpdater;

/** Every minute: follow running smart updates and start new ones inside the maintenance window. */
final class SmartUpdatesCommand extends Command
{
    protected $signature = 'whp:wordpress:smart-updates';

    protected $description = 'Run the scheduled smart WordPress updates (maintenance window, screenshot check)';

    public function handle(SmartUpdater $updater): int
    {
        $stats = $updater->tick();
        if ($this->output->isVerbose()) {
            $this->info("followed {$stats['followed']}, started {$stats['started']}, pruned {$stats['pruned']}");
        }

        return self::SUCCESS;
    }
}
