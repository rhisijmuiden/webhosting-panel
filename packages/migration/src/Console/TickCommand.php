<?php

declare(strict_types=1);

namespace Rhis\Migration\Console;

use Illuminate\Console\Command;
use Rhis\Migration\Services\Migrator;

class TickCommand extends Command
{
    protected $signature = 'migration:tick';

    protected $description = 'Record finished migration copy jobs and start queued domains';

    public function handle(Migrator $migrator): int
    {
        $migrator->tick();

        return self::SUCCESS;
    }
}
