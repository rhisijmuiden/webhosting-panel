<?php

declare(strict_types=1);

namespace Rhis\Waf\Testing;

use App\Services\Agent\FakeAgentClient;

/** Answers of the fake agent (local development and tests) for the firewall actions. */
final class FakeAgentResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('waf.status', ['available' => true, 'engine' => '3.0.14-1', 'crs' => '4.14.0-1', 'sites' => [], 'log_size' => 0]);
        $agent->respond('waf.apply', function (array $p) {
            $modes = array_count_values(array_column($p['sites'] ?? [], 'mode'));

            return ['applied_at' => time(), 'block' => $modes['block'] ?? 0, 'detect' => $modes['detect'] ?? 0, 'off' => $modes['off'] ?? 0, 'missing' => []];
        });
        $agent->respond('waf.events', fn (array $p) => ['events' => [], 'cursor' => ['inode' => 1, 'offset' => (int) ($p['cursor']['offset'] ?? 0)], 'more' => false]);
    }
}
