<?php

declare(strict_types=1);

namespace Rhis\SiteLogs\Http;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The log viewer, shared by admins and customers. The agent reads the files (sitelog.*). */
abstract class LogController extends Controller
{
    public const LOGS = ['access', 'error', 'php', 'fpm'];

    public const STATUS = ['2xx', '3xx', '4xx', '5xx', 'other'];

    public const LEVELS = ['error', 'warning', 'notice', 'info', 'deprecated'];

    /** 'admin' or 'client': route names and layout. */
    protected string $area;

    public function __construct(protected readonly AgentClient $agent) {}

    /** 404 unless this user may read the logs of this website. */
    abstract protected function authorizeSite(Request $request, Website $website): void;

    /** Websites this user may open. */
    abstract protected function websites(Request $request): Collection;

    public function index(Request $request): View|RedirectResponse
    {
        $websites = $this->websites($request);
        if ($websites->count() === 1 && $this->area === 'client') {
            return redirect()->route('client.site-logs.show', $websites->first());
        }

        return view('site-logs::index', ['websites' => $websites, 'area' => $this->area]);
    }

    public function show(Request $request, Website $website): View
    {
        $this->authorizeSite($request, $website);
        $log = in_array($request->query('log'), self::LOGS, true) ? $request->query('log') : 'access';

        return view('site-logs::show', [
            'website' => $website,
            'area' => $this->area,
            'config' => [
                'log' => $log,
                'q' => mb_substr((string) $request->query('q', ''), 0, 200),
                'filesUrl' => route("{$this->area}.site-logs.files", $website),
                'readUrl' => route("{$this->area}.site-logs.read", $website),
                'interval' => (int) config('site-logs.tail_interval', 3),
                'domain' => $website->domain,
            ],
        ]);
    }

    public function files(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);

        return $this->agentJson(fn () => $this->agent->call('sitelog.files', ['domain' => $website->domain]));
    }

    public function read(Request $request, Website $website): JsonResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'log' => ['required', Rule::in(self::LOGS)],
            'file' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.-]+$/'],
            'lines' => ['nullable', 'integer', 'min:10', 'max:1000'],
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'array', 'max:5'],
            'status.*' => [Rule::in(self::STATUS)],
            'levels' => ['nullable', 'array', 'max:5'],
            'levels.*' => [Rule::in(self::LEVELS)],
            'ip' => ['nullable', 'string', 'max:64', 'regex:/^[0-9a-fA-F:.]+$/'],
            'method' => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z]+$/'],
            'inode' => ['nullable', 'integer', 'min:0', 'required_with:offset'],
            'offset' => ['nullable', 'integer', 'min:0', 'required_with:inode'],
        ]);

        $params = array_filter([
            'domain' => $website->domain,
            'log' => $data['log'],
            'file' => $data['file'] ?? null,
            'lines' => isset($data['lines']) ? (int) $data['lines'] : 300,
            'q' => trim(str_replace(["\n", "\r", "\0"], ' ', (string) ($data['q'] ?? ''))) ?: null,
            'status' => array_values(array_unique($data['status'] ?? [])) ?: null,
            'levels' => array_values(array_unique($data['levels'] ?? [])) ?: null,
            'ip' => $data['ip'] ?? null,
            'method' => $data['method'] ?? null,
        ], fn ($v) => $v !== null);
        if (isset($data['inode'], $data['offset'])) {
            $params['after'] = ['inode' => (int) $data['inode'], 'offset' => (int) $data['offset']];
        }

        return $this->agentJson(fn () => $this->agent->call('sitelog.read', $params, 60));
    }

    private function agentJson(\Closure $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (AgentException $e) {
            $status = match ($e->errorCode) {
                'not_found' => 404, 'invalid_params' => 422, 'forbidden' => 403, 'unreachable' => 503, default => 500,
            };
            $message = match ($e->errorCode) {
                'unreachable' => __('The server agent is not reachable. Try again in a moment.'),
                'forbidden' => __('This log file cannot be read safely: it is a link or not a normal file.'),
                'not_found' => __('This website is not known on the server yet.'),
                default => __('The log could not be read.'),
            };

            return response()->json(['message' => $message, 'code' => $e->errorCode], $status);
        }
    }
}
