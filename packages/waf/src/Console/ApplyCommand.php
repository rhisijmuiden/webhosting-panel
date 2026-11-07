<?php

declare(strict_types=1);

namespace Rhis\Waf\Console;

use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Rhis\Waf\Waf;

final class ApplyCommand extends Command
{
    protected $signature = 'whp:waf:apply';

    protected $description = 'Send the web application firewall settings of every website to the server';

    public function handle(Waf $waf): int
    {
        try {
            $r = $waf->apply();
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(sprintf('Web application firewall: %d blocking, %d detect only, %d off.', $r['block'] ?? 0, $r['detect'] ?? 0, $r['off'] ?? 0));
        if (! empty($r['missing'])) {
            $this->warn('Not on the server yet: '.implode(', ', $r['missing']));
        }

        return self::SUCCESS;
    }
}
