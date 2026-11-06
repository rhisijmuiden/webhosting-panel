<?php

declare(strict_types=1);

namespace Rhis\Subdomains\Console;

use Illuminate\Console\Command;
use Rhis\Subdomains\CloneManager;
use Rhis\Subdomains\Models\SiteClone;

/** Start clones whose new subdomain is ready and finish the ones the agent completed. */
final class CloneTickCommand extends Command
{
    protected $signature = 'site-clone:tick';

    protected $description = 'Move running website clones on';

    public function handle(CloneManager $clones): int
    {
        $busy = SiteClone::whereIn('status', ['waiting', 'running'])->get();
        $clones->syncAll($busy);
        // A clone that waits for a subdomain that never comes is given up after a day.
        SiteClone::where('status', 'waiting')->where('created_at', '<', now()->subDay())
            ->update(['status' => 'failed', 'error' => 'The target website was not ready within a day.', 'finished_at' => now()]);

        return self::SUCCESS;
    }
}
