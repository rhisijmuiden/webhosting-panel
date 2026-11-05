<?php

namespace App\Services;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;

class VersionService
{
    public function __construct(private readonly AgentClient $agent) {}

    public function version(): string
    {
        $file = config('panel.version_file');

        return is_readable($file) ? trim((string) file_get_contents($file)) : '0.0.0';
    }

    public function channel(): string
    {
        $channel = config('panel.channel');

        return array_key_exists($channel, config('panel.channels')) ? $channel : 'production';
    }

    /** Version details from the agent, falling back to what the panel knows itself. */
    public function details(): array
    {
        try {
            $data = $this->agent->call('panel.version', [], 10);
        } catch (AgentException) {
            $data = [];
        }

        return array_merge(['version' => $this->version(), 'channel' => $this->channel(), 'commit' => null, 'branch' => null, 'commit_date' => null], $data);
    }
}
