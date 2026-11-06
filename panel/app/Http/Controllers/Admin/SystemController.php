<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\VersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Version, update check, in-panel update and the production/test channel. */
class SystemController extends Controller
{
    public function __construct(private readonly AgentClient $agent) {}

    public function index(VersionService $versions, AgentClient $agent): View
    {
        try {
            $system = $agent->call('system.info');
        } catch (AgentException) {
            $system = null;
        }

        return view('admin.system.index', ['version' => $versions->details(), 'system' => $system]);
    }

    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['channel' => ['nullable', Rule::in(array_keys(config('panel.channels')))]]);

        return $this->agentJson(fn () => $this->agent->call('panel.check_update', array_filter($data), 150));
    }

    public function update(): JsonResponse
    {
        ActivityLog::record('system.update_started', config('panel.channel'));

        return $this->agentJson(fn () => $this->agent->call('panel.update', [], 30));
    }

    public function channel(Request $request): JsonResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(array_keys(config('panel.channels')))]]);
        ActivityLog::record('system.channel_changed', $data['channel']);

        return $this->agentJson(fn () => $this->agent->call('panel.set_channel', $data, 30));
    }

    public function job(string $job): JsonResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}$/', $job), 404);

        return $this->agentJson(fn () => $this->agent->call('job.status', ['job' => $job], 10));
    }

    private function agentJson(\Closure $call): JsonResponse
    {
        try {
            return response()->json($call());
        } catch (AgentException $e) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], 502);
        }
    }
}
