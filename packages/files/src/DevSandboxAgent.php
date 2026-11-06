<?php

declare(strict_types=1);

namespace Rhis\Files;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Local development only (WHP_AGENT_DRIVER=fake and WHP_FILES_DEV_SANDBOX=true):
 * answers files.* by running the real fileworker.py on a demo webspace in
 * storage/app/files-sandbox/<domain>, so the file manager can be tried and
 * screenshotted without a server. Every other action goes to the fake agent.
 */
final class DevSandboxAgent implements AgentClient
{
    public function __construct(private readonly AgentClient $inner) {}

    public function call(string $action, array $params = [], int $timeout = 30): array
    {
        if ($action === 'terminal.status') {
            return ['available' => true];
        }
        if ($action === 'job.status' && Cache::has('files-dev-job:'.($params['job'] ?? ''))) {
            return Cache::get('files-dev-job:'.$params['job']);
        }
        if (! str_starts_with($action, 'files.')) {
            return $this->inner->call($action, $params, $timeout);
        }
        $root = $this->root((string) $params['domain']);
        $op = substr($action, 6);
        $request = array_diff_key($params, ['domain' => 1, 'spool' => 1]) + ['op' => $op, 'root' => $root, 'protected' => ['logs', 'tmp', 'sessions', 'public']];
        $spool = isset($params['spool']) ? config('files.spool_dir').'/'.$params['spool'] : null;
        $fd = $spool ? fopen($spool, str_starts_with($params['spool'], 'up') || str_starts_with($params['spool'], 'wr') ? 'rb' : 'wb') : null;
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        if ($fd) {
            $descriptors[3] = $fd;
        }
        $proc = proc_open(['python3', '-I', base_path('../agent/whp_agent/fileworker.py')], $descriptors, $pipes, null, ['WHP_DATA_FD' => $fd ? '3' : '-1', 'PATH' => '/usr/bin:/bin']);
        fwrite($pipes[0], json_encode($request));
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $log = array_filter(explode("\n", (string) stream_get_contents($pipes[2])));
        proc_close($proc);
        if ($fd) {
            fclose($fd);
        }
        $result = json_decode((string) $out, true) ?: ['ok' => false, 'error' => ['code' => 'internal', 'message' => 'worker failed']];
        if (in_array($op, ['compress', 'extract'], true)) {
            $job = (string) Str::uuid();
            Cache::put('files-dev-job:'.$job, ['id' => $job, 'status' => $result['ok'] ? 'succeeded' : 'failed', 'log' => array_values($log),
                'result' => $result['data'] ?? null, 'error' => $result['error'] ?? null], now()->addHour());

            return ['job' => $job];
        }
        if (! $result['ok']) {
            $code = $result['error']['code'];
            throw new AgentException(['exists' => 'conflict', 'binary' => 'invalid_params', 'too_large' => 'invalid_params'][$code] ?? $code,
                $result['error']['message'], ['reason' => $code]);
        }

        return $result['data'] + ($op === 'list' ? ['protected' => $request['protected'], 'docroot' => 'public'] : []);
    }

    private function root(string $domain): string
    {
        $root = storage_path('app/files-sandbox/'.preg_replace('/[^a-z0-9.-]/', '', $domain));
        if (! is_dir($root)) {
            foreach (['public/wp-content/themes/rhis', 'public/assets/css', 'public/assets/img', 'logs', 'tmp', 'sessions'] as $d) {
                @mkdir("$root/$d", 0755, true);
            }
            file_put_contents("$root/public/index.php", "<?php\n// Front controller\nrequire __DIR__.'/wp-blog-header.php';\n");
            file_put_contents("$root/public/wp-config.php", "<?php\ndefine('DB_NAME', 'klant_wp');\ndefine('DB_USER', 'klant_wp');\n\$table_prefix = 'wp_';\n");
            file_put_contents("$root/public/.htaccess", "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n");
            file_put_contents("$root/public/assets/css/site.css", ":root {\n  --nacht: #14213D;\n  --koraal: #FF6B57;\n}\nbody { font-family: Archivo, sans-serif; color: var(--nacht); }\n");
            file_put_contents("$root/public/assets/img/logo.svg", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="#FF6B57"/></svg>');
            file_put_contents("$root/public/wp-content/themes/rhis/functions.php", "<?php\n\nadd_action('wp_enqueue_scripts', function () {\n    wp_enqueue_style('rhis', get_stylesheet_uri(), [], '1.4.2');\n});\n\nfunction rhis_excerpt_length(int \$length): int\n{\n    return 28; // words\n}\nadd_filter('excerpt_length', 'rhis_excerpt_length');\n");
            file_put_contents("$root/logs/access.log", "203.0.113.7 - - [06/Nov/2026:09:12:01 +0100] \"GET / HTTP/2.0\" 200 5123\n");
            $zip = new \ZipArchive;
            if ($zip->open("$root/public/theme-backup.zip", \ZipArchive::CREATE) === true) {
                $zip->addFromString('rhis/style.css', '/* Theme Name: RHIS */');
                $zip->close();
            }
        }

        return $root;
    }
}
