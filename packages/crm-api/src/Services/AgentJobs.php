<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Services;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

/** Start an agent job and wait for it (used inside queued jobs only). */
final class AgentJobs
{
    public function __construct(private readonly AgentClient $agent) {}

    public function run(string $action, array $params, int $timeoutSeconds = 7200): array
    {
        $job = $this->agent->call($action, $params, 60)['job'] ?? null;
        if (! is_string($job)) {
            throw new AgentException('internal', "{$action} did not start a job");
        }
        $deadline = time() + $timeoutSeconds;
        do {
            $state = $this->agent->call('job.status', ['job' => $job], 15);
            if (($state['status'] ?? 'running') !== 'running') {
                if ($state['status'] !== 'succeeded') {
                    throw new AgentException('command_failed', $state['error']['message'] ?? "{$action} failed");
                }

                return $state['result'] ?? [];
            }
            sleep(5);
        } while (time() < $deadline);

        throw new AgentException('timeout', "{$action} did not finish in time");
    }
}
