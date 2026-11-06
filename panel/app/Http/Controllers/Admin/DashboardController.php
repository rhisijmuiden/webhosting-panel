<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(AgentClient $agent): View
    {
        $system = $services = $security = null;
        $agentError = null;
        try {
            $system = $agent->call('system.info');
            $services = $agent->call('service.list')['services'] ?? [];
            $security = $agent->call('crowdsec.metrics');
        } catch (AgentException $e) {
            $agentError = $e->getMessage();
        }

        return view('admin.dashboard', [
            'system' => $system,
            'services' => $services,
            'security' => $security,
            'agentError' => $agentError,
            'counts' => [
                'customers' => Customer::count(),
                'websites' => Website::count(),
                'ssl_pending' => Website::where('ssl_status', 'pending')->count(),
                'php_versions' => Website::selectRaw('php_version, count(*) as total')->groupBy('php_version')->pluck('total', 'php_version'),
            ],
            'latestCustomers' => Customer::with('package')->withCount('websites')->latest()->limit(6)->get(),
        ]);
    }
}
