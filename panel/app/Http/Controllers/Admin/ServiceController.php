<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ServiceController extends Controller
{
    public function index(AgentClient $agent): View
    {
        try {
            $services = $agent->call('service.list')['services'] ?? [];
            $error = null;
        } catch (AgentException $e) {
            $services = [];
            $error = $e->getMessage();
        }

        return view('admin.services.index', compact('services', 'error'));
    }

    public function restart(AgentClient $agent, string $service): RedirectResponse
    {
        abort_unless(preg_match('/^[a-z0-9.@-]{1,64}$/', $service), 404);
        try {
            $agent->call('service.restart', ['service' => $service], 90);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('service.restarted', $service);

        return back()->with('status', __(':service restarted.', ['service' => $service]));
    }
}
