<?php

namespace App\Services\WpImport;

use App\Models\ActivityLog;
use App\Models\SiteDatabase;
use App\Models\Website;
use App\Models\WpImport;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Databases\DatabaseManager;
use Illuminate\Support\Str;

/**
 * The WordPress import wizard: the customer uploads a zip of the WordPress
 * folder and a database export, the agent checks both (wp_import.inspect),
 * the customer confirms the database, address and path changes, and the
 * agent imports (wp_import.run): files, new database + user, wp-config.php,
 * search-replace, check.
 */
class WpImportManager
{
    /** Upload chunk size (no PHP or Nginx upload limit in the way). */
    public const CHUNK = 8 * 1024 * 1024;

    public function __construct(private readonly AgentClient $agent) {}

    /** Why this website cannot get an import now, or null. */
    public function blocker(Website $website): ?string
    {
        if ($website->status !== 'active') {
            return __('The website is not active.');
        }
        if (WpImport::where('website_id', $website->id)->whereIn('status', ['checking', 'running'])->exists()) {
            return __('An import for this website is already running.');
        }

        return null;
    }

    public function start(Website $website, ?int $userId = null): WpImport
    {
        if ($error = $this->blocker($website)) {
            throw new WpImportException($error);
        }
        // An unfinished import of the same website is continued, not doubled.
        $open = WpImport::where('website_id', $website->id)->whereIn('status', ['upload', 'checked', 'failed'])->latest('id')->first();
        if ($open) {
            return $open;
        }

        return WpImport::create([
            'uuid' => (string) Str::uuid(), 'customer_id' => $website->customer_id, 'website_id' => $website->id,
            'domain' => $website->domain, 'user_id' => $userId, 'status' => 'upload', 'files' => [],
        ]);
    }

    public static function maxBytes(): int
    {
        return max(1, (int) config('wp-import.max_upload_gb', 20)) * 1024 ** 3;
    }

    /** Start (or restart) one upload. */
    public function startUpload(WpImport $import, string $kind, string $name, int $size): void
    {
        $this->assertStatus($import, ['upload', 'failed', 'checked']);
        $allowed = $kind === 'site' ? '/\.zip$/i' : '/\.(zip|sql|sql\.gz|gz)$/i';
        if (! preg_match($allowed, $name)) {
            throw new WpImportException($kind === 'site' ? __('Choose a .zip file.') : __('Choose a .zip, .sql or .sql.gz file.'));
        }
        if ($size < 1 || $size > self::maxBytes()) {
            throw new WpImportException(__('This file is larger than :n GB.', ['n' => config('wp-import.max_upload_gb', 20)]));
        }
        $dir = $this->dir();
        $free = @disk_free_space($dir);
        if ($free !== false && $free < $size * 2 + 1024 ** 3) {
            throw new WpImportException(__('There is not enough free space on the server for this file.'));
        }
        $path = $this->path($import, $kind);
        file_put_contents($path, '');
        @chmod($path, 0640);
        $files = $import->files ?? [];
        $files[$kind] = ['name' => mb_substr($name, 0, 200), 'size' => $size, 'complete' => false];
        // New files mean a new check.
        $import->update(['files' => $files, 'status' => 'upload', 'report' => null, 'error' => null, 'options' => null]);
    }

    /** Append one chunk at $offset. Returns how many bytes are in. */
    public function chunk(WpImport $import, string $kind, int $offset, $stream): int
    {
        $meta = $import->file($kind);
        if (! $meta || $import->status !== 'upload') {
            throw new WpImportException(__('Start the upload again.'));
        }
        $path = $this->path($import, $kind);
        clearstatcache(true, $path);
        $have = is_file($path) ? filesize($path) : -1;
        if ($have !== $offset) {
            throw new WpImportOffsetException($have);
        }
        $out = fopen($path, 'ab');
        $written = stream_copy_to_stream($stream, $out, self::CHUNK + 1);
        fclose($out);
        clearstatcache(true, $path);
        $have = filesize($path);
        if ($written > self::CHUNK || $have > $meta['size']) {
            @unlink($path);
            $files = $import->files;
            unset($files[$kind]);
            $import->update(['files' => $files]);
            throw new WpImportException(__('The upload is larger than announced.'));
        }
        if ($have === $meta['size']) {
            $files = $import->files;
            $files[$kind]['complete'] = true;
            $import->update(['files' => $files]);
        }

        return $have;
    }

    /** Both files are in: let the agent check them. */
    public function check(WpImport $import): void
    {
        $this->assertStatus($import, ['upload', 'failed', 'checked']);
        foreach (WpImport::KINDS as $kind) {
            clearstatcache(true, $this->path($import, $kind));
            if (! $import->uploaded($kind) || @filesize($this->path($import, $kind)) !== ($import->file($kind)['size'] ?? -1)) {
                throw new WpImportException(__('Upload both files first.'));
            }
        }
        if ($error = $this->blocker($import->website)) {
            throw new WpImportException($error);
        }
        try {
            $job = $this->agent->call('wp_import.inspect', ['import' => $import->uuid, 'domain' => $import->domain], 60);
        } catch (AgentException $e) {
            throw new WpImportException($e->getMessage());
        }
        $import->update(['status' => 'checking', 'job_id' => $job['job'] ?? null, 'report' => null, 'error' => null, 'log' => []]);
        $this->sync($import);
    }

    /**
     * @param  array{replace?: bool, new_url?: string, paths?: list<string>, urls?: list<string>, disable?: list<string>, extra?: list<array{0: string, 1: string}>}  $options
     */
    public function run(WpImport $import, array $options, ?int $userId = null): void
    {
        $this->assertStatus($import, ['checked']);
        if (! $import->checkPassed()) {
            throw new WpImportException(__('The check found problems. Fix them and upload the files again.'));
        }
        if ($error = $this->blocker($import->website)) {
            throw new WpImportException($error);
        }
        $params = [
            'import' => $import->uuid, 'domain' => $import->domain, 'replace' => (bool) ($options['replace'] ?? false),
            'new_url' => (string) ($options['new_url'] ?? ($import->report['target']['url'] ?? '')),
            'paths' => array_values($options['paths'] ?? []), 'urls' => array_values($options['urls'] ?? []),
            'disable' => array_values($options['disable'] ?? []), 'extra' => array_values($options['extra'] ?? []),
        ];
        try {
            $job = $this->agent->call('wp_import.run', $params, 60);
        } catch (AgentException $e) {
            throw new WpImportException($e->getMessage());
        }
        $import->update(['status' => 'running', 'job_id' => $job['job'] ?? null, 'options' => array_diff_key($params, ['import' => 1, 'domain' => 1]),
            'error' => null, 'log' => [], 'started_at' => now(), 'finished_at' => null, 'user_id' => $userId ?? $import->user_id]);
        ActivityLog::record('wordpress.import_started', $import->domain, ['url' => $params['new_url']], $import->customer_id);
        $this->sync($import);
    }

    /** Follow the running agent job. */
    public function sync(WpImport $import): void
    {
        if (! $import->busy() || ! $import->job_id) {
            return;
        }
        try {
            $job = $this->agent->call('job.status', ['job' => $import->job_id], 10);
        } catch (AgentException $e) {
            if ($e->errorCode === 'not_found') {
                $import->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
            }

            return;
        }
        $import->update(['log' => array_slice($job['log'] ?? [], -200)]);
        if (($job['status'] ?? 'running') === 'running') {
            return;
        }
        $checking = $import->status === 'checking';
        if ($job['status'] !== 'succeeded') {
            $import->update(['status' => $checking ? 'upload' : 'failed', 'finished_at' => $checking ? null : now(),
                'error' => $job['error']['message'] ?? __('The import failed.')]);
            if (! $checking) {
                ActivityLog::record('wordpress.import_failed', $import->domain, ['error' => Str::limit((string) $import->error, 200)], $import->customer_id);
            }

            return;
        }
        if ($checking) {
            $import->update(['status' => 'checked', 'report' => $this->panelChecks($import, (array) ($job['result'] ?? []))]);

            return;
        }
        $this->finish($import, (array) ($job['result'] ?? []));
    }

    /** Checks only the panel can make: disk quota and the package's database limit. */
    public function panelChecks(WpImport $import, array $report): array
    {
        $website = $import->website;
        $checks = $report['checks'] ?? [];
        $sizeMb = (int) ceil((($report['site']['size'] ?? 0)) / 1024 / 1024);
        $quota = $website->disk_quota_mb ?: $website->package?->disk_mb;
        if ($quota) {
            $free = max(0, $quota - (int) $website->disk_used_mb);
            $state = $sizeMb > $quota ? 'bad' : ($sizeMb > $free ? 'warn' : 'ok');
            $checks[] = ['key' => 'disk_space', 'state' => $state, 'detail' => "{$sizeMb} MB / {$quota} MB",
                'size_mb' => $sizeMb, 'quota_mb' => $quota, 'free_mb' => $free];
        }
        $left = DatabaseManager::left($website);
        $previous = $report['target']['previous_databases'] ?? [];
        if ($left !== null) {
            $checks[] = ['key' => 'database_limit', 'state' => $left > 0 ? 'ok' : 'bad', 'detail' => (string) $left, 'left' => $left,
                'replaces' => count($previous)];
        }
        $report['checks'] = $checks;
        $report['ok'] = ($report['ok'] ?? false) && collect($checks)->doesntContain(fn ($c) => $c['state'] === 'bad');

        return $report;
    }

    private function finish(WpImport $import, array $result): void
    {
        $website = $import->website;
        foreach ((array) ($result['dropped_databases'] ?? []) as $name) {
            SiteDatabase::where('website_id', $website->id)->where('name', $name)->delete();
        }
        if ($name = $result['database']['name'] ?? null) {
            SiteDatabase::updateOrCreate(['name' => $name], ['website_id' => $website->id, 'users' => [$name], 'origin' => 'import']);
        }
        if (isset($result['size_mb'])) {
            $website->update(['disk_used_mb' => (int) $result['size_mb'], 'profile' => 'wordpress']);
        }
        $import->update(['status' => 'done', 'result' => $result, 'finished_at' => now(), 'error' => null]);
        ActivityLog::record('wordpress.imported', $import->domain, ['url' => $result['url'] ?? null, 'database' => $name], $import->customer_id);
        if (class_exists(\Rhis\WordPress\Toolkit::class)) {
            try {
                app(\Rhis\WordPress\Toolkit::class)->discover($website, false);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        $this->discard($import, false);
    }

    /** Remove the uploaded files (and the agent's report). */
    public function discard(WpImport $import, bool $delete = true): void
    {
        foreach (WpImport::KINDS as $kind) {
            @unlink($this->path($import, $kind));
        }
        try {
            $this->agent->call('wp_import.discard', ['import' => $import->uuid], 30);
        } catch (AgentException $e) {
            report($e);
        }
        if ($delete) {
            $import->delete();
        }
    }

    /** Back to the upload step (after a failed import or to use other files). */
    public function reset(WpImport $import): void
    {
        $this->assertStatus($import, ['upload', 'checked', 'failed']);
        foreach (WpImport::KINDS as $kind) {
            @unlink($this->path($import, $kind));
        }
        $import->update(['status' => 'upload', 'files' => [], 'report' => null, 'options' => null, 'error' => null, 'log' => [],
            'job_id' => null, 'started_at' => null, 'finished_at' => null, 'result' => null]);
    }

    public function path(WpImport $import, string $kind): string
    {
        abort_unless(in_array($kind, WpImport::KINDS, true) && Str::isUuid($import->uuid), 404);

        return $this->dir().'/'.strtolower($import->uuid).'-'.$kind.'.upload';
    }

    private function dir(): string
    {
        $dir = (string) config('wp-import.upload_dir');
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return $dir;
    }

    private function assertStatus(WpImport $import, array $allowed): void
    {
        if (! in_array($import->status, $allowed, true)) {
            throw new WpImportException(__('This step is not possible now.'));
        }
    }
}
