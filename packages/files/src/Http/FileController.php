<?php

declare(strict_types=1);

namespace Rhis\Files\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\Files\FileManager;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** File manager screens and JSON endpoints, shared by admins and customers. */
abstract class FileController extends Controller
{
    /** 'admin' or 'client': route names and layout. */
    protected string $area;

    public function __construct(protected readonly FileManager $files) {}

    /** 404 unless this user may manage the files of this website. */
    abstract protected function authorizeSite(Request $request, Website $website): void;

    /** Websites this user may open. */
    abstract protected function websites(Request $request);

    abstract protected function terminalAllowed(Request $request, Website $website): bool;

    protected function route(string $name, mixed $params = []): string
    {
        return route("{$this->area}.files.{$name}", $params);
    }

    public function index(Request $request): View|RedirectResponse
    {
        $websites = $this->websites($request);
        if ($websites->count() === 1 && $this->area === 'client') {
            return redirect($this->route('browse', $websites->first()));
        }

        return view('files::index', ['websites' => $websites, 'area' => $this->area]);
    }

    public function browse(Request $request, Website $website): View
    {
        $this->authorizeSite($request, $website);

        return view('files::browse', [
            'website' => $website, 'area' => $this->area,
            'path' => $this->cleanPath((string) $request->query('path', '')),
            'terminal' => $this->terminalAllowed($request, $website),
            'config' => $this->clientConfig($website),
        ]);
    }

    public function edit(Request $request, Website $website): View
    {
        $this->authorizeSite($request, $website);
        $path = $this->cleanPath((string) $request->query('path', ''));
        abort_if($path === '', 404);

        return view('files::edit', [
            'website' => $website, 'area' => $this->area, 'path' => $path,
            'language' => FileManager::language($path), 'config' => $this->clientConfig($website),
        ]);
    }

    public function list(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);

        return $this->agentJson(fn () => $this->files->call($website, 'list', ['path' => $this->cleanPath((string) $request->query('path', ''))]));
    }

    public function search(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100'], 'path' => ['nullable', 'string', 'max:4096']]);

        return $this->agentJson(fn () => $this->files->call($website, 'search', ['path' => $this->cleanPath($data['path'] ?? ''), 'query' => $data['q']]));
    }

    public function read(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $path = $this->cleanPath((string) $request->query('path', ''));

        return $this->agentJson(fn () => $this->files->read($website, $path) + ['language' => FileManager::language($path)]);
    }

    public function write(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'path' => ['required', 'string', 'max:4096'],
            'content' => ['present', 'nullable', 'string', 'max:'.((int) config('files.editor_max_kb'))],
            'expected_mtime' => ['nullable', 'regex:/^\d{1,24}$/'],
            'create' => ['sometimes', 'boolean'],
        ]);
        abort_if(strlen((string) $data['content']) > (int) config('files.editor_max_kb') * 1024, 413);

        return $this->agentJson(function () use ($website, $data) {
            $result = $this->files->write($website, $this->cleanPath($data['path']), (string) $data['content'],
                isset($data['expected_mtime']) ? (string) $data['expected_mtime'] : null, (bool) ($data['create'] ?? false));
            ActivityLog::record('files.edit', $website->domain.'/'.$result['path'], [], $website->customer_id);

            return $result;
        });
    }

    public function upload(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'upload_id' => ['required', 'string', 'regex:/^[a-zA-Z0-9-]{8,64}$/'],
            'index' => ['required', 'integer', 'min:0'],
            'total' => ['required', 'integer', 'min:1', 'max:100000'],
            'dir' => ['nullable', 'string', 'max:4096'],
            'name' => ['required', 'string', 'max:1024'],
            'overwrite' => ['sometimes', 'boolean'],
            'chunk' => ['required', 'file', 'max:'.((int) config('files.chunk_mb') * 1024 + 64)],
        ]);

        return $this->agentJson(function () use ($website, $data, $request) {
            $result = $this->files->uploadChunk($website, $data['upload_id'], (int) $data['index'], (int) $data['total'],
                $request->file('chunk')->getRealPath(), $this->cleanPath($data['dir'] ?? ''), $data['name'], (bool) ($data['overwrite'] ?? false));
            if ($result === null) {
                return ['done' => false];
            }
            ActivityLog::record('files.upload', $website->domain.'/'.$result['path'], ['bytes' => $result['size'] ?? null], $website->customer_id);

            return ['done' => true] + $result;
        });
    }

    public function download(Request $request, Website $website): BinaryFileResponse|RedirectResponse
    {
        $this->authorizeSite($request, $website);
        try {
            $meta = $this->files->download($website, $this->cleanPath((string) $request->query('path', '')));
        } catch (AgentException $e) {
            return back()->withErrors(['files' => $e->getMessage()]);
        }

        return response()->download($meta['file'], $meta['name'], ['Content-Type' => 'application/octet-stream'],
            ResponseHeaderBag::DISPOSITION_ATTACHMENT)->deleteFileAfterSend();
    }

    public function mkdir(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate(['dir' => ['nullable', 'string', 'max:4096'], 'name' => ['required', 'string', 'max:255'], 'type' => ['sometimes', 'in:dir,file']]);

        return $this->agentJson(fn () => $this->files->call($website, 'mkdir', ['dir' => $this->cleanPath($data['dir'] ?? ''), 'name' => $data['name'], 'type' => $data['type'] ?? 'dir']));
    }

    public function rename(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate(['path' => ['required', 'string', 'max:4096'], 'name' => ['required', 'string', 'max:255']]);

        return $this->agentJson(fn () => $this->files->call($website, 'rename', ['path' => $this->cleanPath($data['path']), 'name' => $data['name']]));
    }

    public function move(Request $request, Website $website): JsonResponse
    {
        return $this->transfer($request, $website, 'move');
    }

    public function copy(Request $request, Website $website): JsonResponse
    {
        return $this->transfer($request, $website, 'copy');
    }

    private function transfer(Request $request, Website $website, string $action): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate(['paths' => ['required', 'array', 'min:1', 'max:1000'], 'paths.*' => ['string', 'max:4096'], 'to' => ['nullable', 'string', 'max:4096']]);

        return $this->agentJson(fn () => $this->files->call($website, $action, ['paths' => $this->cleanPaths($data['paths']), 'to' => $this->cleanPath($data['to'] ?? '')], 900));
    }

    public function delete(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate(['paths' => ['required', 'array', 'min:1', 'max:1000'], 'paths.*' => ['string', 'max:4096']]);

        return $this->agentJson(function () use ($website, $data) {
            $paths = $this->cleanPaths($data['paths']);
            $result = $this->files->call($website, 'delete', ['paths' => $paths], 900);
            ActivityLog::record('files.delete', $website->domain, ['paths' => array_slice($paths, 0, 50)], $website->customer_id);

            return $result;
        });
    }

    public function chmod(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate(['paths' => ['required', 'array', 'min:1', 'max:1000'], 'paths.*' => ['string', 'max:4096'], 'mode' => ['required', 'regex:/^[0-7]{3,4}$/']]);

        return $this->agentJson(fn () => $this->files->call($website, 'chmod', ['paths' => $this->cleanPaths($data['paths']), 'mode' => $data['mode']]));
    }

    public function compress(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'paths' => ['required', 'array', 'min:1', 'max:1000'], 'paths.*' => ['string', 'max:4096'],
            'dir' => ['nullable', 'string', 'max:4096'], 'name' => ['nullable', 'string', 'max:255'], 'format' => ['sometimes', 'in:zip,tar.gz'],
        ]);

        return $this->agentJson(fn () => ['job' => $this->files->startJob($website, 'compress', array_filter([
            'paths' => $this->cleanPaths($data['paths']), 'dir' => $this->cleanPath($data['dir'] ?? ''),
            'name' => $data['name'] ?? null, 'format' => $data['format'] ?? 'zip',
        ], fn ($v) => $v !== null))]);
    }

    public function extract(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate(['path' => ['required', 'string', 'max:4096'], 'to' => ['nullable', 'string', 'max:4096'], 'overwrite' => ['sometimes', 'boolean']]);

        return $this->agentJson(function () use ($website, $data) {
            $job = $this->files->startJob($website, 'extract', [
                'path' => $this->cleanPath($data['path']), 'to' => $this->cleanPath($data['to'] ?? ''), 'overwrite' => (bool) ($data['overwrite'] ?? false),
            ]);
            ActivityLog::record('files.extract', $website->domain.'/'.$this->cleanPath($data['path']), [], $website->customer_id);

            return ['job' => $job];
        });
    }

    public function job(Request $request, Website $website, string $job): JsonResponse
    {
        $this->authorizeSite($request, $website);

        return $this->agentJson(function () use ($website, $job) {
            $status = $this->files->job($website, $job);

            return ['status' => $status['status'] ?? 'running', 'log' => array_slice($status['log'] ?? [], -20),
                'result' => $status['result'] ?? null, 'error' => $status['error']['message'] ?? null];
        });
    }

    public function terminal(Request $request, Website $website): View
    {
        $this->authorizeSite($request, $website);
        abort_unless($this->terminalAllowed($request, $website), 404);
        $token = session("files.terminal.{$website->id}");

        return view('files::terminal', [
            'website' => $website, 'area' => $this->area,
            'session' => $token && FileManager::terminalAllowed($request->user()->id, "/terminal/{$token}/") ? "/terminal/{$token}/" : null,
        ]);
    }

    public function terminalStart(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        abort_unless($this->terminalAllowed($request, $website), 404);
        $this->files->terminalStop($website);
        try {
            $this->files->terminalStart($website);
        } catch (AgentException $e) {
            return back()->withErrors(['terminal' => $e->getMessage()]);
        }
        ActivityLog::record('files.terminal', $website->domain, [], $website->customer_id);

        return redirect($this->route('terminal', $website));
    }

    public function terminalStop(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $this->files->terminalStop($website);

        return redirect($this->route('terminal', $website))->with('status', __('The terminal session was closed.'));
    }

    /** Run an agent call and answer JSON; agent errors become a 422 with a readable message. */
    protected function agentJson(\Closure $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (AgentException $e) {
            $status = match ($e->errorCode) {
                'not_found' => 404, 'forbidden' => 403, 'conflict' => 409, 'unreachable', 'timeout' => 503, default => 422,
            };

            return response()->json(['error' => ['code' => $e->details['reason'] ?? $e->errorCode, 'message' => $e->getMessage()]], $status);
        }
    }

    protected function cleanPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        abort_if(str_contains($path, "\0") || strlen($path) > 4096, 400);
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            abort_if($part === '..', 400);
            $parts[] = $part;
        }

        return implode('/', $parts);
    }

    protected function cleanPaths(array $paths): array
    {
        return array_values(array_map(fn ($p) => $this->cleanPath((string) $p), $paths));
    }

    protected function clientConfig(Website $website): array
    {
        return [
            'base' => $this->route('browse', $website),
            'urls' => collect(['list', 'search', 'read', 'write', 'upload', 'download', 'mkdir', 'rename', 'move', 'copy', 'delete', 'chmod', 'compress', 'extract', 'edit'])
                ->mapWithKeys(fn ($n) => [$n => $this->route($n, $website)])->all(),
            'job' => str_replace('00000000-0000-0000-0000-000000000000', '__JOB__', $this->route('job', [$website, '00000000-0000-0000-0000-000000000000'])),
            'chunk' => (int) config('files.chunk_mb') * 1024 * 1024,
            'maxUpload' => (int) config('files.max_upload_mb') * 1024 * 1024,
            'domain' => $website->domain,
            'docroot' => trim((string) ($website->profile === 'laravel' ? 'app/public' : 'public'), '/'),
        ];
    }
}
