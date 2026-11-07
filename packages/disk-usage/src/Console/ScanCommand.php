<?php

declare(strict_types=1);

namespace Rhis\DiskUsage\Console;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Console\Command;

/** Nightly: let the agent rescan every website whose last disk scan is old (runs in the background on the server). */
final class ScanCommand extends Command
{
    protected $signature = 'disk-usage:scan {--all : Rescan every website, also the ones scanned recently}';

    protected $description = 'Refresh the disk usage explorer of every website';

    public function handle(AgentClient $agent): int
    {
        try {
            $job = $agent->call('disk.scan_all', ['max_age_hours' => $this->option('all') ? 0 : (int) config('disk-usage.max_age_hours', 20)], 30);
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Disk scan started (job '.($job['job'] ?? '?').').');

        return self::SUCCESS;
    }
}
