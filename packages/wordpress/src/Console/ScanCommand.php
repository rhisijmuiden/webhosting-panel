<?php

declare(strict_types=1);

namespace Rhis\WordPress\Console;

use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Rhis\WordPress\Toolkit;

/** Finds WordPress on every website and stores what can be updated. */
final class ScanCommand extends Command
{
    protected $signature = 'whp:wordpress:scan {domain? : Only this website}';

    protected $description = 'Find WordPress installs and check core, plugins and themes for updates';

    public function handle(Toolkit $toolkit): int
    {
        $sites = Website::query()->where('status', 'active')
            ->when($this->argument('domain'), fn ($q, $d) => $q->where('domain', $d))
            ->orderBy('domain')->get();
        $found = 0;
        foreach ($sites as $site) {
            try {
                $found += count($toolkit->discover($site));
            } catch (AgentException $e) {
                $this->warn("{$site->domain}: {$e->getMessage()}");
            }
        }
        $this->info("{$found} WordPress installs checked on {$sites->count()} websites.");

        return self::SUCCESS;
    }
}
