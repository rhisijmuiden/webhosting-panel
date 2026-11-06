<?php

declare(strict_types=1);

namespace Rhis\Backups\Http;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Rhis\Backups\ClientBackups;
use Rhis\Backups\Models\ClientBackup;
use Rhis\Backups\Models\ClientBackupTarget;

/**
 * Restore from an uploaded backup zip. The browser sends the file in chunks
 * (no PHP or Nginx upload limit in the way); the agent checks the zip before
 * anything is extracted.
 */
final class ClientUploadController extends Controller
{
    public const CHUNK = 8 * 1024 * 1024;

    public function __construct(private readonly AgentClient $agent, private readonly ClientBackups $backups) {}

    public function start(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $max = (int) config('backups.client_max_upload_gb', 20) * 1024 ** 3;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/\.zip$/i'],
            'size' => ['required', 'integer', 'min:22', 'max:'.$max],
        ]);
        $dir = $this->dir();
        if (disk_free_space($dir) !== false && disk_free_space($dir) < $data['size'] * 2 + 1024 ** 3) {
            return response()->json(['message' => __('There is not enough free space on the server for this file.')], 507);
        }
        $id = (string) Str::uuid();
        touch($this->path($id));
        @chmod($this->path($id), 0640);
        Cache::put("backups.upload.{$id}", ['customer_id' => $customer->id, 'size' => (int) $data['size'], 'name' => $data['name']], now()->addDay());

        return response()->json(['id' => $id, 'chunk_size' => self::CHUNK]);
    }

    /** Append one chunk. ?offset= must match what was received so far. */
    public function chunk(Request $request, string $upload): JsonResponse
    {
        $meta = $this->meta($request, $upload);
        $path = $this->path($upload);
        clearstatcache(true, $path);
        $have = filesize($path);
        if ((int) $request->query('offset', -1) !== $have) {
            return response()->json(['message' => 'Unexpected offset', 'received' => $have], 409);
        }
        $in = $request->getContent(true);
        $out = fopen($path, 'ab');
        $written = stream_copy_to_stream($in, $out, self::CHUNK + 1);
        fclose($out);
        clearstatcache(true, $path);
        if ($written > self::CHUNK || filesize($path) > $meta['size']) {
            @unlink($path);
            Cache::forget("backups.upload.{$upload}");

            return response()->json(['message' => __('The upload is larger than announced.')], 422);
        }

        return response()->json(['received' => filesize($path)]);
    }

    /** All chunks are in: let the agent read the manifest. */
    public function inspect(Request $request, string $upload): JsonResponse
    {
        $meta = $this->meta($request, $upload);
        clearstatcache(true, $this->path($upload));
        if (filesize($this->path($upload)) !== $meta['size']) {
            return response()->json(['message' => __('The upload is not complete.')], 422);
        }
        try {
            $info = $this->agent->call('client_backup.inspect_upload', ['upload' => $upload], 120);
        } catch (AgentException $e) {
            $this->discard($upload);

            return response()->json(['message' => $e->getMessage()], 422);
        }
        Cache::put("backups.upload.{$upload}", $meta + ['inspected' => $info], now()->addDay());
        $websites = $this->customer($request)->websites()->orderBy('domain')->get(['id', 'domain']);

        return response()->json(['backup' => $info, 'suggested_website' => $websites->firstWhere('domain', $info['domain'] ?? null)?->id]);
    }

    public function restore(Request $request, string $upload)
    {
        $meta = $this->meta($request, $upload);
        abort_unless(isset($meta['inspected']), 422);
        $data = $request->validate([
            'website' => ['required', 'integer'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*' => [Rule::in(ClientBackupTarget::PARTS)],
            'safety_target' => ['nullable', 'integer'],
        ]);
        $website = Website::where('customer_id', $meta['customer_id'])->findOrFail($data['website']);
        $safety = ! empty($data['safety_target'])
            ? ClientBackupTarget::where('customer_id', $meta['customer_id'])->findOrFail($data['safety_target']) : null;
        if (ClientBackup::where('website_id', $website->id)->where('status', 'running')->exists()) {
            return response()->json(['message' => __('A backup or restore of this website is already running.')], 409);
        }
        try {
            $record = $this->backups->restoreUpload($upload, $website, array_values($data['parts']), $safety, $meta['name']);
        } catch (AgentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        Cache::forget("backups.upload.{$upload}");

        return response()->json(['redirect' => route('client.backups.jobs.show', $record->job_id)]);
    }

    public function destroy(Request $request, string $upload): JsonResponse
    {
        $this->meta($request, $upload);
        $this->discard($upload);

        return response()->json(['discarded' => true]);
    }

    private function discard(string $upload): void
    {
        @unlink($this->path($upload));
        Cache::forget("backups.upload.{$upload}");
    }

    private function meta(Request $request, string $upload): array
    {
        abort_unless(Str::isUuid($upload), 404);
        $meta = Cache::get("backups.upload.{$upload}");
        abort_unless(is_array($meta) && $meta['customer_id'] === $this->customer($request)->id && is_file($this->path($upload)), 404);

        return $meta;
    }

    private function dir(): string
    {
        $dir = (string) config('backups.upload_dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        return $dir;
    }

    private function path(string $upload): string
    {
        return $this->dir().'/'.strtolower($upload).'.zip';
    }

    private function customer(Request $request)
    {
        abort_unless(config('backups.customer_targets') && config('backups.customer_upload_restore'), 404);
        $customer = $request->user()->customer;
        abort_unless($customer !== null, 404);

        return $customer;
    }
}
