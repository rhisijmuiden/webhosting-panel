<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Traffic;
use Illuminate\Console\Command;

class CollectTraffic extends Command
{
    protected $signature = 'whp:traffic:collect';

    protected $description = 'Store the data traffic per website and check the traffic limits of the packages';

    public function handle(Traffic $traffic): int
    {
        try {
            $traffic->collect();
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
