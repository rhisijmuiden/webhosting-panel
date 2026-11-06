<?php

declare(strict_types=1);

namespace Rhis\Git\Console;

use Illuminate\Console\Command;
use Rhis\Git\Deployer;

final class CollectCommand extends Command
{
    protected $signature = 'whp:git:collect';

    protected $description = 'Store the outcome of finished Git deployments and start queued ones';

    public function handle(Deployer $deployer): int
    {
        $n = $deployer->collect();
        $this->info("{$n} deployment(s) finished");

        return self::SUCCESS;
    }
}
