<?php

namespace App\Console\Commands;

use App\Services\Metrics;
use Illuminate\Console\Command;

class RollupMetrics extends Command
{
    protected $signature = 'whp:metrics:rollup';

    protected $description = 'Average usage samples into hours and days and remove old detail';

    public function handle(Metrics $metrics): int
    {
        $metrics->rollup();

        return self::SUCCESS;
    }
}
