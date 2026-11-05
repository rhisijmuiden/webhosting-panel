<?php

namespace App\Services\Agent;

interface AgentClient
{
    /**
     * Call an action on whp-agent and return its data.
     *
     * @throws AgentException when the agent is unreachable or the action fails
     */
    public function call(string $action, array $params = [], int $timeout = 30): array;
}
