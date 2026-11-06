<?php

declare(strict_types=1);

namespace Rhis\Monitoring\Console;

use Illuminate\Console\Command;
use Rhis\Monitoring\Monitor;

class RunCommand extends Command
{
    protected $signature = 'whp:monitor:run {--force : Check now, also when the interval has not passed}';

    protected $description = 'Check websites, certificates, disks and services, and send alerts';

    public function handle(Monitor $monitor): int
    {
        if (! $this->option('force') && ! $monitor->due()) {
            return self::SUCCESS;
        }
        $r = $monitor->run();
        $this->info("{$r['checked']} checks, {$r['problems']} problems, {$r['events']} alerts");

        return self::SUCCESS;
    }
}
