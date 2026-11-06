<?php

declare(strict_types=1);

namespace Rhis\WordPress\Console;

use Illuminate\Console\Command;
use Rhis\WordPress\Toolkit;

final class CollectCommand extends Command
{
    protected $signature = 'whp:wordpress:collect';

    protected $description = 'Store the outcome of finished WordPress update jobs';

    public function handle(Toolkit $toolkit): int
    {
        $this->info($toolkit->collect().' finished jobs stored.');

        return self::SUCCESS;
    }
}
