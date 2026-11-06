<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentException;
use App\Services\Visits;
use Illuminate\Console\Command;

class CollectVisits extends Command
{
    protected $signature = 'whp:visits:collect';

    protected $description = 'Store visitors, page views and requests per website (from the Nginx traffic log)';

    public function handle(Visits $visits): int
    {
        try {
            $visits->collect();
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
