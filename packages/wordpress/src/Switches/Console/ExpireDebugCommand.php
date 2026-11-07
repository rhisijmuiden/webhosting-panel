<?php

declare(strict_types=1);

namespace Rhis\WordPress\Switches\Console;

use Illuminate\Console\Command;
use Rhis\WordPress\Switches\Switches;

final class ExpireDebugCommand extends Command
{
    protected $signature = 'whp:wordpress:debug-expire';

    protected $description = 'Switch WordPress debug mode off where its time is up';

    public function handle(Switches $switches): int
    {
        $n = $switches->expireDebug();
        if ($n) {
            $this->info("Debug mode switched off on {$n} install(s).");
        }

        return self::SUCCESS;
    }
}
