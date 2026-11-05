<?php

namespace App\Services\Agent;

use Illuminate\Support\Str;

/** Talks to whp-agent over its Unix socket: one JSON line out, one JSON line back. */
class SocketAgentClient implements AgentClient
{
    public function __construct(private readonly string $socketPath) {}

    public function call(string $action, array $params = [], int $timeout = 30): array
    {
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client('unix://'.$this->socketPath, $errno, $errstr, 5);
        if ($stream === false) {
            throw AgentException::unreachable("Cannot reach the agent at {$this->socketPath}: {$errstr}");
        }

        try {
            stream_set_timeout($stream, $timeout);
            $id = (string) Str::uuid();
            $payload = json_encode([
                'id' => $id,
                'action' => $action,
                'params' => (object) $params,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

            if (fwrite($stream, $payload) === false) {
                throw AgentException::unreachable('Could not send the request to the agent');
            }

            $line = fgets($stream);
            $meta = stream_get_meta_data($stream);
            if ($line === false) {
                throw $meta['timed_out']
                    ? new AgentException('timeout', "The agent did not answer within {$timeout} seconds")
                    : AgentException::unreachable('The agent closed the connection');
            }
        } finally {
            fclose($stream);
        }

        $response = json_decode($line, true);
        if (! is_array($response) || ($response['id'] ?? null) !== $id) {
            throw new AgentException('internal', 'The agent sent an invalid response');
        }
        if (! ($response['ok'] ?? false)) {
            $error = $response['error'] ?? [];
            throw new AgentException($error['code'] ?? 'internal', $error['message'] ?? 'Unknown agent error', $error['details'] ?? []);
        }

        return $response['data'] ?? [];
    }
}
