<?php

declare(strict_types=1);

namespace Rhis\Files;

use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Talks to whp-agent's files.* and terminal.* actions. File contents never
 * go through the agent socket: they pass through spool files that the panel
 * (user whp) and the agent share. The agent runs every operation as the
 * website's own Linux user, jailed to the site root.
 */
final class FileManager
{
    public function __construct(private readonly AgentClient $agent) {}

    public function call(Website $website, string $action, array $params = [], int $timeout = 60): array
    {
        return $this->agent->call("files.{$action}", ['domain' => $website->domain, ...$params], $timeout);
    }

    public function spoolDir(): string
    {
        $dir = (string) config('files.spool_dir');
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $dir;
    }

    /** A fresh spool name: two letter kind + 32 hex characters (the agent checks the format). */
    public function spoolName(string $kind): string
    {
        $this->spoolDir();

        return $kind.'-'.bin2hex(random_bytes(16));
    }

    public function spoolPath(string $name): string
    {
        abort_unless((bool) preg_match('/^[a-z]{2}-[a-f0-9]{32}$/', $name), 400);

        return $this->spoolDir().'/'.$name;
    }

    /** Contents of a text file for the editor. */
    public function read(Website $website, string $path): array
    {
        $name = $this->spoolName('rd');
        try {
            $meta = $this->call($website, 'read', ['path' => $path, 'spool' => $name]);
            $meta['content'] = (string) @file_get_contents($this->spoolPath($name));
        } finally {
            @unlink($this->spoolPath($name));
        }

        return $meta;
    }

    public function write(Website $website, string $path, string $content, ?string $expectedMtime, bool $create = false): array
    {
        $name = $this->spoolName('wr');
        $file = $this->spoolPath($name);
        file_put_contents($file, $content);
        chmod($file, 0600);
        try {
            return $this->call($website, 'write', ['path' => $path, 'spool' => $name, 'expected_mtime' => $expectedMtime, 'create' => $create]);
        } finally {
            @unlink($file);
        }
    }

    /**
     * Append one chunk of an upload to its spool file; after the last chunk the
     * agent moves it into the webspace. Returns null while chunks are missing.
     */
    public function uploadChunk(Website $website, string $uploadId, int $index, int $total, string $chunkFile,
        string $dir, string $name, bool $overwrite): ?array
    {
        $key = "files:upload:{$website->id}:{$uploadId}";
        $state = Cache::get($key, ['spool' => $this->spoolName('up'), 'next' => 0, 'bytes' => 0, 'user' => auth()->id()]);
        abort_unless($state['user'] === auth()->id(), 403);
        if ($index !== $state['next']) {
            throw new AgentException('invalid_params', __('Upload chunks arrived out of order. Please try again.'));
        }
        $file = $this->spoolPath($state['spool']);
        $size = (int) filesize($chunkFile);
        $state['bytes'] += $size;
        if ($state['bytes'] > (int) config('files.max_upload_mb') * 1024 * 1024) {
            @unlink($file);
            Cache::forget($key);
            throw new AgentException('invalid_params', __('The file is larger than :mb MB.', ['mb' => config('files.max_upload_mb')]));
        }
        $in = fopen($chunkFile, 'rb');
        $out = fopen($file, $index === 0 ? 'wb' : 'ab');
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        @chmod($file, 0600);
        $state['next']++;
        if ($state['next'] < $total) {
            Cache::put($key, $state, now()->addHours(6));

            return null;
        }
        Cache::forget($key);
        try {
            return $this->call($website, 'upload', ['dir' => $dir, 'name' => $name, 'overwrite' => $overwrite, 'spool' => $state['spool']], 600);
        } finally {
            @unlink($file);
        }
    }

    /** Download a file (or a folder as zip) into the spool; the caller streams and deletes it. */
    public function download(Website $website, string $path): array
    {
        $name = $this->spoolName('dl');
        $meta = $this->call($website, 'download', ['path' => $path, 'spool' => $name], 1800);
        $meta['file'] = $this->spoolPath($name);

        return $meta;
    }

    /** Start a background job and remember which website (and user) it belongs to. */
    public function startJob(Website $website, string $action, array $params): string
    {
        $job = (string) ($this->call($website, $action, $params)['job'] ?? '');
        Cache::put("files:job:{$job}", ['website' => $website->id, 'user' => auth()->id()], now()->addDay());

        return $job;
    }

    public function job(Website $website, string $job): array
    {
        $owner = Cache::get("files:job:{$job}");
        abort_unless($owner && $owner['website'] === $website->id && $owner['user'] === auth()->id(), 404);

        return $this->agent->call('job.status', ['job' => $job]);
    }

    public function terminalAvailable(): bool
    {
        if (! config('files.terminal')) {
            return false;
        }

        return Cache::remember('files:terminal:available', now()->addMinutes(10), function () {
            try {
                return (bool) ($this->agent->call('terminal.status')['available'] ?? false);
            } catch (AgentException) {
                return false;
            }
        });
    }

    public function terminalStart(Website $website): array
    {
        $minutes = max(5, min(480, (int) config('files.terminal_minutes')));
        $session = $this->agent->call('terminal.start', ['domain' => $website->domain, 'minutes' => $minutes], 45);
        Cache::put('files:terminal:'.$session['token'], ['user' => auth()->id(), 'website' => $website->id], now()->addMinutes($minutes));
        session()->put("files.terminal.{$website->id}", $session['token']);

        return $session;
    }

    public function terminalStop(Website $website): void
    {
        $token = session()->pull("files.terminal.{$website->id}");
        if ($token) {
            Cache::forget('files:terminal:'.$token);
            try {
                $this->agent->call('terminal.stop', ['token' => $token]);
            } catch (AgentException) {
                // Already gone.
            }
        }
    }

    /** Nginx auth_request: may the logged-in user open this terminal? */
    public static function terminalAllowed(?int $userId, string $uri): bool
    {
        if (! $userId || ! preg_match('#^/terminal/([a-f0-9]{32})/#', $uri.'/', $m)) {
            return false;
        }
        $owner = Cache::get('files:terminal:'.$m[1]);

        return $owner && $owner['user'] === $userId;
    }

    /** Language of a file for the editor's mode label. */
    public static function language(string $path): string
    {
        $name = Str::lower(basename($path));

        return match (true) {
            str_ends_with($name, '.blade.php') => 'Blade',
            $name === '.htaccess' => 'Apache',
            $name === '.env' || str_starts_with($name, '.env.') => 'Env',
            default => match (pathinfo($name, PATHINFO_EXTENSION)) {
                'php', 'phtml', 'inc' => 'PHP', 'html', 'htm' => 'HTML', 'css', 'scss', 'less' => 'CSS',
                'js', 'mjs', 'cjs', 'jsx' => 'JavaScript', 'ts', 'tsx' => 'TypeScript', 'json' => 'JSON',
                'md', 'markdown' => 'Markdown', 'sql' => 'SQL', 'xml', 'svg' => 'XML', 'yml', 'yaml' => 'YAML',
                'py' => 'Python', 'sh', 'bash' => 'Shell', 'ini' => 'INI', 'conf' => 'Config', 'twig' => 'Twig', 'vue' => 'Vue',
                default => __('Plain text'),
            },
        };
    }
}
