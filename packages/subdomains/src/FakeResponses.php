<?php

declare(strict_types=1);

namespace Rhis\Subdomains;

use App\Services\Agent\FakeAgentClient;

/** site.clone answers for the fake agent (WHP_AGENT_DRIVER=fake, local development and tests). */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('site.clone_plan', fn (array $p) => [
            'source' => $p['source'] ?? '', 'target' => $p['target'] ?? null,
            'installs' => [['kind' => 'wordpress', 'path' => '', 'version' => '6.8.3', 'config' => 'wp-config.php',
                'db_name' => 'example_wp_1a2b', 'db_user' => 'example_wp_1a2b', 'db_host' => 'localhost', 'problem' => null]],
            'target_content' => [], 'size_mb' => 184, 'target_url' => isset($p['target']) ? 'https://'.$p['target'] : null,
        ]);
        $agent->respond('site.clone', fn (array $p) => ['job' => 'fake-clone-'.md5(($p['source'] ?? '').($p['target'] ?? ''))]);
    }
}
