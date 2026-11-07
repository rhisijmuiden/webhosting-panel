<?php

declare(strict_types=1);

namespace Rhis\Cron\Console;

use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Rhis\Cron\CronManager;

/** Every minute: keep the finished cron job runs from the server and mail failures. */
class SyncCommand extends Command
{
    protected $signature = 'whp:cron:sync';

    protected $description = 'Fetch finished cron job runs from the server, keep their output and mail customers about failures';

    public function handle(CronManager $cron): int
    {
        try {
            $new = $cron->syncRuns();
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("{$new} new run(s)");

        return self::SUCCESS;
    }
}
