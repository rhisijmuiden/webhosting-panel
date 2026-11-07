<?php

declare(strict_types=1);

namespace Rhis\SiteLogs;

use App\Services\Agent\FakeAgentClient;

/** sitelog.* answers for the fake agent (WHP_AGENT_DRIVER=fake): believable demo logs. */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('sitelog.files', fn (array $p) => ['domain' => $p['domain'] ?? '', 'logs' => self::files()]);
        $agent->respond('sitelog.read', fn (array $p) => self::read($p));
    }

    private static function files(): array
    {
        $now = time();
        $set = fn (string $base, array $sizes) => array_map(fn ($size, $n) => [
            'name' => $n === 0 ? $base : $base.'.'.$n.($n > 1 ? '.gz' : ''),
            'n' => $n, 'size' => $size, 'modified' => $now - $n * 86400, 'compressed' => $n > 1,
        ], $sizes, array_keys($sizes));

        return [
            'access' => $set('access.log', [2_480_112, 6_912_448, 801_204, 774_019]),
            'error' => $set('error.log', [18_402, 22_118]),
            'php' => $set('php-error.log', [9_871]),
            'fpm' => $set('site.log', [1_204]),
        ];
    }

    private static function read(array $p): array
    {
        $log = $p['log'] ?? 'access';
        $want = (int) ($p['lines'] ?? 300);
        $after = $p['after'] ?? null;
        $base = strtotime('today 09:00');
        $entries = [];
        if ($after !== null) {
            // A few new requests per tail call.
            $n = random_int(0, 3);
            for ($i = 0; $i < $n; $i++) {
                $entries[] = self::entry($log, random_int(0, 10_000), time());
            }
            $offset = (int) $after['offset'] + 200 * $n;
        } else {
            for ($i = 0; $i < 240; $i++) {
                $entries[] = self::entry($log, $i, $base + $i * 97);
            }
            $offset = 2_480_112;
        }
        $entries = array_values(array_filter($entries, fn ($e) => self::matches($e, $p)));

        return [
            'domain' => $p['domain'] ?? '', 'log' => $log, 'file' => $p['file'] ?? ($log === 'php' ? 'php-error.log' : $log.'.log'),
            'entries' => array_slice($entries, -$want), 'size' => $offset, 'modified' => time(),
            'cursor' => ['inode' => 4242, 'offset' => $offset], 'truncated' => false, 'rotated' => false, 'exists' => true,
            'filtered' => ! empty($p['q']) || ! empty($p['status']) || ! empty($p['levels']) || ! empty($p['ip']) || ! empty($p['method']),
        ];
    }

    private static function matches(array $e, array $p): bool
    {
        foreach (preg_split('/\s+/', trim((string) ($p['q'] ?? ''))) ?: [] as $term) {
            if ($term === '') {
                continue;
            }
            $hit = stripos($e['raw'], ltrim($term, '-')) !== false;
            if ($term[0] === '-' ? $hit : ! $hit) {
                return false;
            }
        }
        if (! empty($p['status']) && ! in_array(intdiv((int) ($e['status'] ?? 0), 100).'xx', $p['status'], true)) {
            return false;
        }
        if (! empty($p['levels']) && ! in_array($e['level'] ?? null, $p['levels'], true)) {
            return false;
        }
        if (! empty($p['ip']) && ($e['ip'] ?? null) !== $p['ip']) {
            return false;
        }

        return empty($p['method']) || ($e['method'] ?? null) === strtoupper($p['method']);
    }

    private static function entry(string $log, int $i, int $ts): array
    {
        $time = date('Y-m-d\TH:i:sP', $ts);
        $ips = ['203.0.113.24', '198.51.100.7', '192.0.2.81', '2001:db8::5', '203.0.113.99', '198.51.100.160'];
        $ip = $ips[min(count($ips) - 1, intdiv(($i * 37) % 100, 17))];
        if ($log === 'access') {
            $routes = [
                ['GET', '/', 200], ['GET', '/wp-content/themes/rhis/style.css', 200], ['GET', '/contact/', 200],
                ['POST', '/wp-admin/admin-ajax.php', 200], ['GET', '/blog/nieuwe-website-live/', 200], ['GET', '/wp-login.php', 200],
                ['GET', '/oude-pagina', 301], ['GET', '/favicon.ico', 404], ['POST', '/xmlrpc.php', 403], ['GET', '/.env', 404],
                ['GET', '/shop/?add-to-cart=12', 302], ['GET', '/wp-json/wc/v3/orders', 500], ['GET', '/images/hero.webp', 304],
            ];
            [$method, $path, $status] = $routes[($i * 7) % count($routes)];
            $agents = ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 Version/18.1 Mobile Safari/604.1',
                'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'curl/8.11.1'];
            $agent = $agents[$i % count($agents)];
            $bytes = $status === 304 ? 0 : 400 + ($i * 1373) % 48000;
            $rt = round(0.002 + (($i * 37) % 900) / 1000, 3);
            $cache = $method === 'GET' && $status === 200 ? ($i % 3 ? 'HIT' : 'MISS') : null;

            return ['raw' => sprintf('%s - - [%s] "%s %s HTTP/2.0" %d %d "-" "%s" rt=%.3f cache=%s', $ip, date('d/M/Y:H:i:s O', $ts), $method, $path, $status, $bytes, $agent, $rt, $cache ?? '-'),
                'time' => $time, 'ip' => $ip, 'user' => null, 'method' => $method, 'path' => $path, 'protocol' => 'HTTP/2.0',
                'status' => $status, 'bytes' => $bytes, 'referer' => $i % 4 ? null : 'https://www.google.com/', 'agent' => $agent,
                'duration' => $rt, 'cache' => $cache];
        }
        if ($log === 'error') {
            $rows = [
                ['error', 'open() "/var/www/vhosts/klant.nl/public/favicon.ico" failed (2: No such file or directory)', 'GET /favicon.ico HTTP/2.0'],
                ['warning', 'an upstream response is buffered to a temporary file /var/cache/nginx/fastcgi_temp/1/00/0000000001 while reading upstream', 'GET /wp-json/wc/v3/orders HTTP/2.0'],
                ['error', 'access forbidden by rule', 'GET /.env HTTP/1.1'],
                ['error', 'upstream timed out (110: Connection timed out) while reading response header from upstream', 'POST /wp-admin/admin-ajax.php HTTP/2.0'],
                ['notice', 'limiting requests, excess: 5.120 by zone "whp_login"', 'POST /wp-login.php HTTP/2.0'],
            ];
            [$level, $message, $request] = $rows[$i % count($rows)];

            return ['raw' => date('Y/m/d H:i:s', $ts)." [$level] 1201#1201: *$i $message, client: $ip, server: klant.nl, request: \"$request\"",
                'time' => date('Y-m-d\TH:i:s', $ts), 'level' => $level, 'message' => $message, 'ip' => $ip, 'request' => $request, 'upstream' => null];
        }
        if ($log === 'php') {
            $rows = [
                ['warning', 'Undefined array key "utm_source" in /var/www/vhosts/klant.nl/public/wp-content/themes/rhis/functions.php on line 214', '/var/www/vhosts/klant.nl/public/wp-content/themes/rhis/functions.php', 214],
                ['deprecated', 'Creation of dynamic property Rhis_Slider::$speed is deprecated in /var/www/vhosts/klant.nl/public/wp-content/plugins/rhis-slider/slider.php on line 48', '/var/www/vhosts/klant.nl/public/wp-content/plugins/rhis-slider/slider.php', 48],
                ['error', "Uncaught TypeError: count(): Argument #1 (\$value) must be of type Countable|array, null given in /var/www/vhosts/klant.nl/public/wp-content/plugins/shop-sync/sync.php:91\nStack trace:\n#0 /var/www/vhosts/klant.nl/public/wp-includes/class-wp-hook.php(324): ShopSync->run()\n#1 /var/www/vhosts/klant.nl/public/wp-includes/plugin.php(517): WP_Hook->do_action()\n#2 {main}\n  thrown in /var/www/vhosts/klant.nl/public/wp-content/plugins/shop-sync/sync.php on line 91", '/var/www/vhosts/klant.nl/public/wp-content/plugins/shop-sync/sync.php', 91],
                ['notice', 'Function _load_textdomain_just_in_time was called incorrectly in /var/www/vhosts/klant.nl/public/wp-includes/functions.php on line 6114', '/var/www/vhosts/klant.nl/public/wp-includes/functions.php', 6114],
            ];
            [$level, $message, $file, $line] = $rows[$i % count($rows)];
            $kind = ['warning' => 'Warning', 'deprecated' => 'Deprecated', 'error' => 'Fatal error', 'notice' => 'Notice'][$level];

            return ['raw' => '['.gmdate('d-M-Y H:i:s', $ts)." UTC] PHP $kind:  $message", 'time' => gmdate('Y-m-d\TH:i:s', $ts).'+00:00',
                'level' => $level, 'message' => $message, 'file' => $file, 'line' => $line];
        }
        $rows = [
            ['notice', 'fpm is running, pid 1180'], ['notice', 'ready to handle connections'],
            ['warning', '[pool site-klant_nl] server reached pm.max_children setting (5), consider raising it'],
            ['warning', '[pool site-klant_nl] child 2210 said into stderr: "Cron reschedule event error for hook: wp_update_plugins"'],
        ];
        [$level, $message] = $rows[$i % count($rows)];

        return ['raw' => '['.date('d-M-Y H:i:s', $ts).'] '.strtoupper($level).": $message", 'time' => date('Y-m-d\TH:i:s', $ts), 'level' => $level, 'message' => $message];
    }
}
