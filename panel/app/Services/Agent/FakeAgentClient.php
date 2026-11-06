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
            'backup.status' => [
                'version' => 'fake', 'running' => false,
                'targets' => [['name' => 'nas', 'type' => 'sftp', 'host' => 'nas.example.test', 'port' => '22', 'user' => 'backup', 'path' => 'whp/web01', 'enabled' => true, 'created' => '2026-11-01T10:00:00Z']],
                'last_run' => ['kind' => 'incremental', 'status' => 'ok', 'host' => 'web01.example.test', 'started' => now()->subHours(6)->toIso8601String(), 'finished' => now()->subHours(6)->addMinutes(4)->toIso8601String(),
                    'targets' => [['target' => 'nas', 'items' => [['scope' => 'system', 'status' => 'ok', 'data_added' => 120000], ['scope' => 'site:example.test', 'status' => 'ok', 'data_added' => 2400000]]]]],
            ],
            'backup.targets' => ['targets' => [['name' => 'nas', 'type' => 'sftp', 'host' => 'nas.example.test', 'port' => '22', 'user' => 'backup', 'path' => 'whp/web01', 'enabled' => true]]],
            'backup.get_schedule', 'backup.schedule' => ['incremental' => ['enabled' => true, 'time' => '02:30'], 'full' => ['enabled' => true, 'day' => 'Sun', 'time' => '03:30']],
            'backup.list' => ['snapshots' => collect(range(0, 6))->map(fn ($d) => [
                'id' => str_pad(dechex(0xA1B2C300 + $d), 64, '0'), 'short_id' => dechex(0xA1B2C300 + $d), 'time' => now()->subDays($d)->setTime(2, 34)->toIso8601String(),
                'host' => 'web01.example.test', 'paths' => [], 'kind' => $d % 7 === 3 ? 'full' : 'incremental', 'scope' => $params['scope'] ?? 'site:example.test', 'size' => null,
            ])->all()],
            'backup.target_test' => ['target' => $params['target'] ?? '', 'reachable' => true, 'repository' => true, 'message' => 'reachable', 'duration_ms' => 42],
            'backup.target_add' => ['name' => $params['name'] ?? '', 'type' => $params['type'] ?? '', 'initialized' => true],
            'backup.target_remove' => ['removed' => $params['target'] ?? '', 'remote_data_kept' => true],
            'backup.target_export_key' => ['target' => $params['target'] ?? '', 'repository_key' => 'fake-repository-key'],
            'backup.run', 'backup.restore', 'backup.check', 'backup.prune' => ['job' => (string) Str::uuid()],
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
            'apps.catalog' => ['apps' => [
                ['id' => 'wordpress', 'name' => 'WordPress', 'subdir' => true, 'locales' => ['en', 'nl']],
                ['id' => 'joomla', 'name' => 'Joomla', 'subdir' => true, 'locales' => ['en']],
                ['id' => 'nextcloud', 'name' => 'Nextcloud', 'subdir' => false, 'locales' => ['en', 'nl']],
            ]],
            'apps.list' => ['apps' => []],
            'apps.install', 'apps.remove' => ['job' => (string) Str::uuid()],
            'db.list' => ['databases' => []],
            'site.show' => ['domain' => $params['domain'] ?? '', 'ssl' => 'imported', 'disk' => ['used_mb' => 412]],
            'migrate.key' => ['public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakeKeyForDevelopmentOnly webhosting-panel-migration@web01', 'fingerprint' => 'SHA256:fakefingerprint'],
            'migrate.connect' => ['type' => 'plesk', 'version' => '18.0.74', 'hostname' => 'plesk.example.test', 'rsync' => true, 'host_key' => 'ED25519 SHA256:fakehostkey'],
            'migrate.forget_host' => ['host' => $params['host'] ?? '', 'forgotten' => true],
            'migrate.inventory', 'migrate.transfer' => ['job' => (string) Str::uuid()],
            'migrate.credentials' => ['domain' => $params['domain'] ?? '', 'mailboxes' => [
                ['local' => 'info', 'scheme' => 'plain', 'secret' => 'Geheim-123'],
                ['local' => 'jan', 'scheme' => 'crypt', 'secret' => '$6$abcdefgh$'.str_repeat('A', 86)],
            ]],
            default => throw new AgentException('unknown_action', "Unknown action: {$action}"),
        };
    }

    /** A small Plesk server, the shape migrate.inventory returns (docs/migration.md). */
    public static function pleskInventory(): array
    {
        $site = fn (string $name, string $sub, array $extra = []) => array_merge([
            'name' => $name, 'kind' => 'site', 'is_subdomain' => false, 'parent' => null, 'subscription' => $name, 'owner_id' => '3',
            'active' => true, 'size_mb' => 412, 'php' => '8.2',
            'web' => ['root' => "/var/www/vhosts/{$name}/httpdocs", 'vhost' => $name, 'base' => "/var/www/vhosts/{$name}/httpdocs", 'sub' => $sub, 'system_user' => 'klant', 'app' => 'wordpress'],
            'aliases' => [], 'databases' => [], 'mailboxes' => [], 'forwarders' => [], 'dns' => [], 'ssl' => null, 'notes' => [],
        ], $extra);

        return [
            'source' => ['type' => 'plesk', 'version' => '18.0.74', 'hostname' => 'plesk.example.test'],
            'server_ips' => ['203.0.113.5'],
            'owners' => [['id' => '3', 'login' => 'bakkerij', 'kind' => 'client', 'name' => 'Piet Bakker', 'company' => 'Bakkerij Bakker', 'email' => 'piet@bakkerij.test', 'phone' => '']],
            'domains' => [
                $site('bakkerij.test', '', [
                    'aliases' => [['name' => 'bakkerij-bakker.test', 'web' => true, 'mail' => false]],
                    'databases' => [['name' => 'wp_bakkerij', 'type' => 'mysql', 'server' => 'local', 'users' => ['wp_bakkerij']]],
                    'mailboxes' => [
                        ['local' => 'info', 'quota_mb' => 1024, 'size_mb' => 230, 'password' => 'plain', 'aliases' => ['bestellingen'], 'forward' => []],
                        ['local' => 'jan', 'quota_mb' => 0, 'size_mb' => 12, 'password' => 'hash', 'aliases' => [], 'forward' => []],
                    ],
                    'forwarders' => [['local' => 'factuur', 'targets' => ['boekhouder@example.org']]],
                    'dns' => [
                        ['type' => 'A', 'name' => 'bakkerij.test', 'content' => '203.0.113.5'],
                        ['type' => 'MX', 'name' => 'bakkerij.test', 'content' => 'mail.bakkerij.test', 'priority' => 10],
                        ['type' => 'TXT', 'name' => 'bakkerij.test', 'content' => 'v=spf1 +a +mx -all'],
                        ['type' => 'TXT', 'name' => 'bakkerij.test', 'content' => 'google-site-verification=abc123'],
                        ['type' => 'CNAME', 'name' => 'shop.bakkerij.test', 'content' => 'shops.myshopify.com'],
                    ],
                    'ssl' => ['name' => 'Lets Encrypt bakkerij.test', 'lets_encrypt' => true],
                ]),
                $site('api.bakkerij.test', 'public', ['is_subdomain' => true, 'parent' => 'bakkerij.test', 'subscription' => 'bakkerij.test', 'php' => '8.4',
                    'web' => ['root' => '/var/www/vhosts/bakkerij.test/api.bakkerij.test/public', 'vhost' => 'bakkerij.test', 'base' => '/var/www/vhosts/bakkerij.test/api.bakkerij.test', 'sub' => 'public', 'system_user' => 'klant', 'app' => 'laravel']]),
            ],
            'totals' => ['domains' => 2, 'sites' => 2, 'databases' => 1, 'mailboxes' => 2, 'size_mb' => 824],
            'warnings' => [],
        ];
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
