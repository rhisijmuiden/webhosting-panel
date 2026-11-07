<?php

declare(strict_types=1);

namespace Rhis\WordPress\DbCleanup;

use Illuminate\Console\Command;

final class CollectCleanupsCommand extends Command
{
    protected $signature = 'whp:wordpress:dbclean-collect';

    protected $description = 'Store the outcome of finished WordPress database cleanups';

    public function handle(Cleaner $cleaner): int
    {
        $this->info($cleaner->collect().' finished cleanups stored.');

        return self::SUCCESS;
    }
}
