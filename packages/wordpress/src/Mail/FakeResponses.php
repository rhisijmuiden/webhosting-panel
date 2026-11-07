<?php

declare(strict_types=1);

namespace Rhis\WordPress\Mail;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;

/** wp.mail_* answers for the fake agent (WHP_AGENT_DRIVER=fake, local development and tests). */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $key = fn (array $p) => 'fake-agent.wp-mail.'.($p['domain'] ?? '').'/'.($p['path'] ?? '');
        $status = function (array $p) use ($key) {
            $cfg = Cache::get($key($p));
            $domain = $p['domain'] ?? '';
            $domains = $cfg['domains'] ?? [$domain];

            return [
                'domain' => $domain, 'path' => $p['path'] ?? '', 'enabled' => $cfg !== null, 'copied' => false,
                'plugin_current' => $cfg !== null, 'staging' => str_starts_with($domain, 'staging.'), 'smtp' => true, 'port' => 10587,
                'from_email' => $cfg['from_email'] ?? null, 'from_name' => $cfg['from_name'] ?? null, 'force_from' => $cfg['force_from'] ?? true,
                'domains' => $domains, 'dkim' => array_fill_keys($domains, true),
                'log' => $cfg === null ? [] : [
                    ['time' => time() - 300, 'ok' => true, 'to' => ['jan@example.org'], 'from' => $cfg['from_email'],
                        'subject' => 'New order #1042', 'queue_id' => '4XkQ2m0ZbLz9', 'error' => null],
                    ['time' => time() - 4000, 'ok' => false, 'to' => ['info@'.$domain], 'from' => $cfg['from_email'],
                        'subject' => 'Contact form: question about delivery', 'queue_id' => null,
                        'error' => 'SMTP Error: Could not connect to SMTP host.'],
                ],
            ];
        };
        $agent->respond('wp.mail_status', $status);
        $agent->respond('wp.mail_apply', function (array $p) use ($key, $status) {
            if ($p['enabled'] ?? false) {
                Cache::forever($key($p), ['from_email' => $p['from_email'] ?? null, 'from_name' => $p['from_name'] ?? '',
                    'force_from' => $p['force_from'] ?? true, 'domains' => $p['domains'] ?? []]);
            } else {
                Cache::forget($key($p));
            }

            return $status($p);
        });
        $agent->respond('wp.mail_test', fn (array $p) => ['to' => $p['to'] ?? '', 'ok' => true, 'error' => '', 'queue_id' => '4XkQ3b1QwPz7',
            'from' => Cache::get($key($p))['from_email'] ?? 'wordpress@'.($p['domain'] ?? ''), 'mailer' => 'smtp', 'host' => '127.0.0.1:10587',
            'routed' => Cache::has($key($p)), 'staging' => false]);
    }
}
