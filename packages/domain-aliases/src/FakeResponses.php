<?php

declare(strict_types=1);

namespace Rhis\DomainAliases;

use App\Services\Agent\FakeAgentClient;

/** site.alias_* answers for the fake agent (WHP_AGENT_DRIVER=fake). */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $one = fn (array $p, string $ssl = 'active') => [
            'domain' => $p['domain'] ?? '',
            'alias' => [
                'domain' => $p['alias'] ?? '', 'mode' => $p['mode'] ?? 'serve', 'www' => (bool) ($p['www'] ?? true),
                'ssl' => $ssl, 'names' => [$p['alias'] ?? ''],
            ],
            'domain_aliases' => [],
        ];
        $agent->respond('site.alias_list', fn (array $p) => ['domain' => $p['domain'] ?? '', 'domain_aliases' => []]);
        $agent->respond('site.alias_add', fn (array $p) => $one($p, ($p['ssl'] ?? true) ? 'active' : 'off'));
        $agent->respond('site.alias_mode', fn (array $p) => $one($p));
        $agent->respond('site.alias_ssl', fn (array $p) => $one($p));
        $agent->respond('site.alias_remove', fn (array $p) => ['domain' => $p['domain'] ?? '', 'alias' => ['domain' => $p['alias'] ?? '', 'removed' => true], 'domain_aliases' => []]);
    }
}
