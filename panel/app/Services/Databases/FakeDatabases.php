<?php

namespace App\Services\Databases;

use App\Services\Agent\AgentException;
use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** db.* answers for the fake agent (WHP_AGENT_DRIVER=fake), kept in the cache like the real state files. */
final class FakeDatabases
{
    private const KEY = 'fake-agent.databases';

    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('db.list', fn (array $p) => self::list($p['domain'] ?? null));
        $agent->respond('db.create', function (array $p) {
            $s = self::state();
            $domain = $p['domain'];
            $name = isset($p['name']) ? self::prefix($domain).$p['name'] : self::prefix($domain).'db_'.Str::lower(Str::random(4));
            if (isset($s['db'][$name]) || isset($s['users'][$name])) {
                throw new AgentException('conflict', "The name {$name} is already in use");
            }
            $s['db'][$name] = ['domain' => $domain, 'user' => $name, 'size' => random_int(1, 40) * 65536, 'tables' => random_int(0, 30)];
            self::save($s);

            return ['name' => $name, 'user' => $name, 'password' => $p['password'] ?? Str::random(32), 'host' => 'localhost'];
        });
        $agent->respond('db.drop', function (array $p) {
            $s = self::state();
            if (($s['db'][$p['name']]['domain'] ?? null) !== $p['domain']) {
                throw new AgentException('not_found', "Database {$p['name']} does not belong to {$p['domain']}");
            }
            unset($s['db'][$p['name']]);
            $dropped = [];
            foreach ($s['users'] as $u => $e) {
                $s['users'][$u]['databases'] = array_values(array_diff($e['databases'], [$p['name']]));
                if (! $s['users'][$u]['databases']) {
                    unset($s['users'][$u]);
                    $dropped[] = $u;
                }
            }
            self::save($s);

            return ['name' => $p['name'], 'dropped' => true, 'dropped_users' => $dropped];
        });
        $agent->respond('db.adopt', function (array $p) {
            $s = self::state();
            $s['db'][$p['name']] ??= ['domain' => $p['domain'], 'user' => $p['user'] ?? $p['name'], 'size' => 2_400_000, 'tables' => 12];
            self::save($s);

            return ['name' => $p['name'], 'user' => $s['db'][$p['name']]['user'], 'adopted' => true];
        });
        $agent->respond('db.user_create', function (array $p) {
            $s = self::state();
            $name = self::prefix($p['domain']).$p['name'];
            if (isset($s['users'][$name]) || isset($s['db'][$name])) {
                throw new AgentException('conflict', "The name {$name} is already in use");
            }
            $s['users'][$name] = ['domain' => $p['domain'], 'databases' => $p['databases'], 'readonly' => (bool) ($p['readonly'] ?? false)];
            self::save($s);

            return ['user' => $name, 'password' => $p['password'] ?? Str::random(32), 'databases' => $p['databases'], 'readonly' => (bool) ($p['readonly'] ?? false), 'host' => 'localhost'];
        });
        $agent->respond('db.user_update', function (array $p) {
            $s = self::state();
            $s['users'][$p['user']]['databases'] = $p['databases'];
            $s['users'][$p['user']]['readonly'] = (bool) $p['readonly'];
            self::save($s);

            return ['user' => $p['user'], 'databases' => $p['databases'], 'readonly' => (bool) $p['readonly']];
        });
        $agent->respond('db.user_drop', function (array $p) {
            $s = self::state();
            unset($s['users'][$p['user']]);
            self::save($s);

            return ['user' => $p['user'], 'dropped' => true];
        });
        $agent->respond('db.password', fn (array $p) => ['user' => $p['user'], 'password' => $p['password'] ?? Str::random(32)]);
        $agent->respond('db.sso', fn (array $p) => ['token' => str_repeat('ab', 32), 'database' => $p['name'], 'expires' => time() + 120, 'path' => '/phpmyadmin/whp-sso.php']);
        $agent->respond('db.sso_cleanup', fn () => ['dropped_users' => [], 'removed_tokens' => 0]);
    }

    public static function prefix(string $domain): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($domain)), '_');

        return trim(substr($base, 0, 20), '_').'_';
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
    }

    private static function state(): array
    {
        return Cache::get(self::KEY, ['db' => [], 'users' => []]);
    }

    private static function save(array $s): void
    {
        Cache::forever(self::KEY, $s);
    }

    private static function list(?string $domain): array
    {
        $s = self::state();
        $rows = [];
        foreach ($s['db'] as $name => $e) {
            if ($domain && $e['domain'] !== $domain) {
                continue;
            }
            $users = [['user' => $e['user'], 'readonly' => false, 'main' => true]];
            foreach ($s['users'] as $u => $x) {
                if ($x['domain'] === $e['domain'] && in_array($name, $x['databases'], true)) {
                    $users[] = ['user' => $u, 'readonly' => $x['readonly'], 'main' => false];
                }
            }
            $rows[] = ['name' => $name, 'user' => $e['user'], 'domain' => $e['domain'], 'size_bytes' => $e['size'], 'tables' => $e['tables'], 'users' => $users];
        }
        $users = [];
        foreach ($s['users'] as $u => $x) {
            if (! $domain || $x['domain'] === $domain) {
                $users[] = ['user' => $u] + $x;
            }
        }

        return ['databases' => $rows, 'users' => $users, 'prefix' => $domain ? self::prefix($domain) : null];
    }
}
