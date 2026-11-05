<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class FirewallController extends Controller
{
    public function index(AgentClient $agent): View
    {
        try {
            $data = $agent->call('firewall.list');
            $error = null;
        } catch (AgentException $e) {
            $data = ['rules' => [], 'protected_tcp_ports' => []];
            $error = $e->getMessage();
        }

        return view('admin.firewall.index', [
            'rules' => $data['rules'] ?? [],
            'protected' => $data['protected_tcp_ports'] ?? [],
            'error' => $error,
        ]);
    }

    public function store(Request $request, AgentClient $agent): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['open_port', 'block_ip'])],
            'protocol' => ['required_if:type,open_port', 'nullable', Rule::in(['tcp', 'udp'])],
            'port' => ['required_if:type,open_port', 'nullable', 'integer', 'min:1', 'max:65535'],
            'address' => ['required_if:type,block_ip', 'nullable', 'string', 'max:64'],
            'comment' => ['nullable', 'string', 'max:120'],
        ]);
        $params = $data['type'] === 'open_port'
            ? ['type' => 'open_port', 'protocol' => $data['protocol'], 'port' => (int) $data['port']]
            : ['type' => 'block_ip', 'address' => $data['address']];
        $params['comment'] = $data['comment'] ?? '';

        try {
            $agent->call('firewall.add', $params, 60);
        } catch (AgentException $e) {
            return back()->withInput()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('firewall.rule_added', null, $params);

        return back()->with('status', __('Firewall rule added.'));
    }

    public function destroy(AgentClient $agent, string $rule): RedirectResponse
    {
        abort_unless(preg_match('/^[0-9a-f]{12}$/', $rule), 404);
        try {
            $agent->call('firewall.delete', ['id' => $rule], 60);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('firewall.rule_deleted', $rule);

        return back()->with('status', __('Firewall rule removed.'));
    }
}
