<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Metrics;
use Illuminate\Console\Command;

class CollectMetrics extends Command
{
    protected $signature = 'whp:metrics:collect';

    protected $description = 'Store one usage sample of the server and every website';

    public function handle(Metrics $metrics): int
    {
        try {
            $metrics->collect();
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
