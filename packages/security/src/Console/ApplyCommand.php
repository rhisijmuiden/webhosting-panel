<?php

declare(strict_types=1);

namespace Rhis\Security\Console;

use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Rhis\Security\LoginProtection;

/** Push the login protection settings to CrowdSec. Run by the installer and by updates. */
final class ApplyCommand extends Command
{
    protected $signature = 'whp:security:apply';

    protected $description = 'Write the login protection settings to CrowdSec through the agent';

    public function handle(LoginProtection $protection): int
    {
        try {
            $result = $protection->apply();
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(count($result['changed'] ?? []).' CrowdSec file(s) changed.');
        if (! empty($result['warning'])) {
            $this->warn($result['warning']);
        }

        return self::SUCCESS;
    }
}
