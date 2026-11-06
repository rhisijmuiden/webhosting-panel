<?php

declare(strict_types=1);

namespace Rhis\WebApps;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Str;

/** Answers for runtime.* and webapp.* when the panel runs without a real agent (WHP_AGENT_DRIVER=fake). */
final class FakeWebApps
{
    public static function register(FakeAgentClient $agent): void
    {
        $status = fn (string $id) => [
            'id' => $id, 'state' => 'running', 'active_state' => 'active', 'sub_state' => 'running',
            'pid' => 1000 + crc32($id) % 30000, 'restarts' => crc32($id) % 3, 'since' => now()->subHours(5 + crc32($id) % 40)->timestamp,
            'memory_bytes' => (60 + crc32($id) % 180) * 1024 * 1024, 'cpu_seconds' => round((crc32($id) % 4000) / 10, 1),
            'tasks' => 11, 'result' => 'success', 'exit_status' => 0, 'enabled' => true, 'port' => 30000 + crc32($id) % 20,
        ];
        $agent->respond('runtime.list', [
            'uv' => true,
            'node' => [
                ['version' => '22', 'installed' => true, 'full_version' => 'v22.21.1', 'eol_date' => '2027-04-30', 'eol' => false, 'apps' => 0, 'lts' => 'Jod', 'latest' => 'v22.21.1', 'update_available' => false],
                ['version' => '24', 'installed' => true, 'full_version' => 'v24.11.0', 'eol_date' => '2028-04-30', 'eol' => false, 'apps' => 2, 'lts' => 'Krypton', 'latest' => 'v24.11.1', 'update_available' => true],
                ['version' => '26', 'installed' => false, 'full_version' => null, 'eol_date' => '2029-04-30', 'eol' => false, 'apps' => 0, 'lts' => 'Lithium', 'latest' => 'v26.13.0', 'update_available' => false],
            ],
            'python' => [
                ['version' => '3.11', 'installed' => false, 'full_version' => null, 'eol_date' => '2027-10-31', 'eol' => false, 'apps' => 0],
                ['version' => '3.12', 'installed' => true, 'full_version' => '3.12.12', 'eol_date' => '2028-10-31', 'eol' => false, 'apps' => 0],
                ['version' => '3.13', 'installed' => true, 'full_version' => '3.13.9', 'eol_date' => '2029-10-31', 'eol' => false, 'apps' => 1],
                ['version' => '3.14', 'installed' => false, 'full_version' => null, 'eol_date' => '2030-10-31', 'eol' => false, 'apps' => 0],
            ],
        ]);
        $agent->respond('runtime.install', fn () => ['job' => (string) Str::uuid()]);
        $agent->respond('runtime.remove', fn ($p) => ['removed' => true] + $p);
        $agent->respond('webapp.apply', fn ($p) => ['id' => $p['id'], 'port' => 30000 + crc32($p['id']) % 20, 'unit' => "whp-webapp-{$p['id']}.service", 'status' => $status($p['id'])]);
        $agent->respond('webapp.status', fn ($p) => $status($p['id']));
        $agent->respond('webapp.list', fn ($p) => ['apps' => collect($p['ids'] ?? [])->mapWithKeys(fn ($id) => [$id => $status($id)])->all()]);
        $agent->respond('webapp.control', fn ($p) => ['op' => $p['op']] + $status($p['id']));
        $agent->respond('webapp.install', fn () => ['job' => (string) Str::uuid()]);
        $agent->respond('webapp.remove', fn ($p) => ['id' => $p['id'], 'removed' => true]);
        $agent->respond('webapp.suspend', fn ($p) => ['apps' => []] + $p);
        $agent->respond('webapp.logs', fn ($p) => ['id' => $p['id'], 'lines' => collect([
            '> shop-api@2.4.0 start', '> node server.js', 'Connected to MariaDB (shop_api)', 'Valkey cache ready',
            'Server listening on http://127.0.0.1:30004', 'GET /api/products 200 18ms', 'GET /api/products/42 200 9ms',
            'POST /api/cart 201 31ms', 'GET /api/health 200 1ms', 'WARN slow query (412 ms): SELECT * FROM orders',
            'GET /api/products?page=2 200 22ms',
        ])->map(fn ($l, $i) => now()->subMinutes(30 - $i * 2)->format('Y-m-d\TH:i:sP').' whp-webapp-'.$p['id'].'[4211]: '.$l)->all()]);
    }
}
