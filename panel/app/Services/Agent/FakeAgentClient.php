<?php

namespace App\Services\Agent;

use App\Models\Website;
use App\Services\Metrics;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * In-memory stand-in for whp-agent, for local development and tests
 * (WHP_AGENT_DRIVER=fake). Returns realistic data, changes nothing.
 */
class FakeAgentClient implements AgentClient
{
    /** @var array<int, array{action: string, params: array}> */
    public array $calls = [];

    /** @var array<string, \Closure|array|AgentException> */
    private array $responses = [];

    public function respond(string $action, \Closure|array|AgentException $response): static
    {
        $this->responses[$action] = $response;

        return $this;
    }

    public function call(string $action, array $params = [], int $timeout = 30): array
    {
        $this->calls[] = ['action' => $action, 'params' => $params];

        if (isset($this->responses[$action])) {
            $response = $this->responses[$action];
            if ($response instanceof AgentException) {
                throw $response;
            }

            return $response instanceof \Closure ? $response($params) : $response;
        }

        return match ($action) {
            'ping' => ['pong' => true, 'agent_version' => 'fake'],
            'system.info' => [
                'hostname' => 'web01.example.test', 'os' => 'Debian GNU/Linux 13 (trixie)', 'os_version' => '13',
                'codename' => 'trixie', 'kernel' => '6.12.0', 'container' => 'lxc', 'uptime_seconds' => 864000,
                'load' => [0.42, 0.38, 0.31], 'cpu_percent' => 12.5, 'cpu_count' => 8,
                'memory' => ['total' => 16 * 1024 ** 3, 'used' => 6 * 1024 ** 3],
                'disk' => ['total' => 400 * 1024 ** 3, 'used' => 120 * 1024 ** 3],
            ],
            'service.list' => ['services' => collect([
                'nginx' => 'Nginx', 'php8.3-fpm' => 'PHP-FPM 8.3', 'php8.4-fpm' => 'PHP-FPM 8.4', 'php8.5-fpm' => 'PHP-FPM 8.5',
                'mariadb' => 'MariaDB', 'valkey-server' => 'Valkey', 'postfix' => 'Postfix', 'dovecot' => 'Dovecot',
                'rspamd' => 'Rspamd', 'unbound' => 'Unbound', 'crowdsec' => 'CrowdSec', 'nftables' => 'nftables',
            ])->map(fn ($label, $unit) => [
                'service' => $unit, 'label' => $label, 'installed' => true, 'active' => true, 'state' => 'running', 'since' => null,
            ])->values()->all()],
            'service.restart', 'service.status' => ['service' => $params['service'] ?? '', 'active' => true, 'state' => 'running'],
            'firewall.list' => ['rules' => Cache::get('fake-agent.firewall', []), 'protected_tcp_ports' => ['22', '25', '80', '443', '465', '587', '993', '8443']],
            'firewall.add' => $this->firewallAdd($params),
            'firewall.delete' => $this->firewallDelete($params),
            'crowdsec.decisions' => ['decisions' => [
                ['id' => 1, 'value' => '203.0.113.7', 'scope' => 'Ip', 'type' => 'ban', 'origin' => 'crowdsec', 'scenario' => 'crowdsecurity/ssh-bf', 'duration' => '3h12m', 'country' => 'NL'],
                ['id' => 2, 'value' => '198.51.100.23', 'scope' => 'Ip', 'type' => 'ban', 'origin' => 'CAPI', 'scenario' => 'crowdsecurity/http-wordpress-scan', 'duration' => '22h1m', 'country' => 'US'],
            ]],
            'crowdsec.metrics' => ['alerts_24h' => 312],
            'crowdsec.ban', 'crowdsec.unban' => ['address' => $params['address'] ?? ''],
            'panel.version' => ['version' => trim((string) @file_get_contents(base_path('../VERSION'))) ?: '0.0.0', 'channel' => config('panel.channel'), 'branch' => 'main', 'commit' => 'dev', 'commit_date' => null],
            'panel.check_update' => [
                'channel' => $params['channel'] ?? config('panel.channel'), 'branch' => 'main',
                'current_version' => '0.1.0', 'latest_version' => '0.1.0', 'current_commit' => 'dev', 'latest_commit' => 'dev',
                'update_available' => false, 'commits_behind' => 0, 'changes' => [],
            ],
            'panel.update', 'panel.set_channel' => ['job' => (string) Str::uuid()],
            'job.status' => ['id' => $params['job'] ?? '', 'status' => 'succeeded', 'log' => ['Done'], 'result' => [], 'error' => null],
            'site.add' => [
                'domain' => $params['domain'], 'user' => 'site'.substr(md5($params['domain']), 0, 6), 'php' => $params['php'] ?? '8.4',
                'aliases' => ['www.'.$params['domain']], 'cache' => $params['cache'] ?? true, 'profile' => $params['profile'] ?? 'php',
                'docroot' => ($params['profile'] ?? 'php') === 'laravel' ? 'app/public' : 'public',
                'ssl' => ($params['ssl'] ?? true) ? 'active' : 'off', 'disk' => ['used_mb' => 1, 'quota_mb' => $params['quota_mb'] ?? null],
            ],
            'site.php' => ['domain' => $params['domain'], 'php' => $params['php']],
            'site.cache' => ['domain' => $params['domain'], 'cache' => $params['enabled']],
            'site.ssl' => ['domain' => $params['domain'], 'ssl' => 'active'],
            'site.remove', 'site.quota' => ['domain' => $params['domain'] ?? ''],
            'site.list' => ['sites' => []],
            'stats.sample' => $this->statsSample(),
            'mail.set_routing' => ['changed' => [], 'local_domains' => collect($params['domains'] ?? [])->where('provider', 'rhis')->pluck('domain')->all()],
            'mail.mailboxes_sync' => ['changed' => [], 'created_homes' => [], 'mailboxes' => count($params['mailboxes'] ?? []), 'aliases' => count($params['aliases'] ?? []), 'skipped_not_local' => []],
            'mail.dkim_generate', 'mail.dkim_get' => ['domain' => $params['domain'] ?? '', 'selector' => 'whp', 'algorithm' => 'rsa',
                'public_key' => 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA'.substr(base64_encode(hash('sha256', (string) ($params['domain'] ?? ''), true)), 0, 40)],
            'mail.mailbox_usage' => ['usage' => []],
            'mail.spam_policy_sync' => ['changed' => false, 'domains' => count($params['domains'] ?? [])],
            'mail.quarantine_list' => $this->quarantine($params['domain'] ?? ''),
            'mail.quarantine_release' => ['user' => $params['user'] ?? '', 'uid' => $params['uid'] ?? 0, 'learned' => true],
            'mail.quarantine_delete' => ['user' => $params['user'] ?? '', 'deleted' => count($params['uids'] ?? []), 'learned' => 0],
            'mail.tlsa' => ['usage' => 3, 'selector' => 1, 'matching_type' => 1, 'certificate' => hash('sha256', 'fake-mail-key'),
                'cert_file' => '/etc/letsencrypt/live/mail.example.test/fullchain.pem', 'not_after' => 'Feb  3 12:00:00 2027 GMT', 'reuse_key' => true],
            default => throw new AgentException('unknown_action', "Unknown action: {$action}"),
        };
    }

    private function quarantine(string $domain): array
    {
        $user = 'info@'.$domain;

        return ['domain' => $domain, 'truncated' => false, 'messages' => [
            ['user' => $user, 'uid' => 12, 'guid' => 'g12', 'date' => now()->subHours(2)->format('Y-m-d H:i:s'), 'size' => 18400,
                'from' => 'Pakketdienst <noreply@track-parcel.example>', 'to' => $user, 'subject' => 'Uw pakket kon niet worden bezorgd', 'score' => 13, 'virus' => false],
            ['user' => $user, 'uid' => 11, 'guid' => 'g11', 'date' => now()->subDay()->format('Y-m-d H:i:s'), 'size' => 52100,
                'from' => 'Leverancier BV <facturen@leverancier.example>', 'to' => $user, 'subject' => 'Factuur 2026-1182', 'score' => 10, 'virus' => false],
            ['user' => $user, 'uid' => 9, 'guid' => 'g9', 'date' => now()->subDays(3)->format('Y-m-d H:i:s'), 'size' => 210000,
                'from' => 'invoice@unknown.example', 'to' => $user, 'subject' => 'Payment overdue', 'score' => 15, 'virus' => true],
        ]];
    }

    private function statsSample(): array
    {
        $h = (int) now()->format('G');
        $day = 0.5 + 0.5 * sin(($h - 8) / 24 * 2 * M_PI);

        return [
            'first_sample' => false, 'interval_seconds' => 60,
            'cpu_percent' => round(8 + 30 * $day + mt_rand(0, 80) / 10, 1), 'load1' => round(0.4 + 2 * $day, 2),
            'memory_used' => (int) ((5 + 3 * $day) * 1024 ** 3), 'memory_total' => 16 * 1024 ** 3, 'swap_used' => 0,
            'disk_used' => 120 * 1024 ** 3, 'disk_total' => 400 * 1024 ** 3,
            'net_rx_bps' => (int) ((200 + 900 * $day) * 1024), 'net_tx_bps' => (int) ((600 + 2400 * $day) * 1024),
            'sites' => Website::pluck('domain')->values()->map(fn ($d, $i) => [
                'site_id' => Metrics::siteId($d), 'cpu_percent' => round((12 / ($i + 1)) * $day + mt_rand(0, 20) / 10, 2),
                'memory_bytes' => (int) ((300 / ($i + 1)) * 1024 ** 2), 'io_read_bps' => 20000, 'io_write_bps' => 9000,
            ])->all(),
        ];
    }

    private function firewallAdd(array $params): array
    {
        $rules = Cache::get('fake-agent.firewall', []);
        $rule = array_merge($params, ['id' => substr(md5(json_encode($params).microtime()), 0, 12), 'created_at' => time()]);
        $rules[] = $rule;
        Cache::forever('fake-agent.firewall', $rules);

        return $rule;
    }

    private function firewallDelete(array $params): array
    {
        $rules = array_values(array_filter(Cache::get('fake-agent.firewall', []), fn ($r) => $r['id'] !== $params['id']));
        Cache::forever('fake-agent.firewall', $rules);

        return ['deleted' => $params['id']];
    }
}
