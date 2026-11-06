<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Console;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Console\Command;
use Rhis\Vulnerabilities\Options;

/** Download the Wordfence Intelligence feed into the agent's local database (only when Wordfence is the source). */
final class SyncCommand extends Command
{
    protected $signature = 'whp:vuln:sync';

    protected $description = 'Download the Wordfence Intelligence vulnerability feed';

    public function handle(AgentClient $agent, Options $options): int
    {
        if ($options->source() !== 'wordfence') {
            $this->info('WPVulnerability.net is used, nothing to download.');

            return self::SUCCESS;
        }
        try {
            $job = $agent->call('vuln.sync', ['api_key' => $options->wordfenceKey()], 30)['job'];
        } catch (AgentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Download started (job {$job}).");

        return self::SUCCESS;
    }
}
