<?php

declare(strict_types=1);

namespace Rhis\WebRules;

use App\Services\Agent\FakeAgentClient;

/** webrules.* answers for the fake agent (WHP_AGENT_DRIVER=fake). */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('webrules.apply', fn (array $p) => [
            'domain' => $p['domain'] ?? '',
            'redirects' => count($p['redirects'] ?? []),
            'protected' => count($p['protected'] ?? []),
            'error_pages' => count((array) ($p['error_pages'] ?? [])),
            'missing_error_pages' => [],
            'force_https' => (bool) ($p['force_https'] ?? true),
            'hsts' => 'max-age=31536000',
            'https_active' => true,
        ]);
        $agent->respond('webrules.status', fn (array $p) => array_filter([
            'geoip' => ['available' => true, 'updated_at' => now()->startOfMonth()->timestamp],
            'domain' => $p['domain'] ?? null,
            'force_https' => isset($p['domain']) ? true : null,
            'https_active' => isset($p['domain']) ? true : null,
        ], fn ($v) => $v !== null));
    }
}
