<?php

declare(strict_types=1);

namespace Rhis\Waf\Console;

use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Rhis\Waf\Waf;

/**
 * Every five minutes: fetch new firewall events, bring new websites under the
 * firewall (applies when the settings changed or a site was not on the server
 * yet) and drop old events.
 */
final class CollectCommand extends Command
{
    protected $signature = 'whp:waf:collect';

    protected $description = 'Collect web application firewall events and keep the server in step with the panel';

    public function handle(Waf $waf): int
    {
        $status = $waf->status();
        if ($status !== null && empty($status['available'])) {
            $this->line('The web application firewall is not installed on this server.');

            return self::SUCCESS;
        }
        $ok = true;
        try {
            if ($waf->applyIfNeeded()) {
                $this->line('Settings sent to the server.');
            }
        } catch (AgentException $e) {
            $this->warn('Applying failed: '.$e->getMessage());
            $ok = false;
        }
        try {
            $this->info($waf->collect().' new events.');
        } catch (AgentException $e) {
            $this->error('Collecting failed: '.$e->getMessage());
            $ok = false;
        }
        $waf->prune();

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
