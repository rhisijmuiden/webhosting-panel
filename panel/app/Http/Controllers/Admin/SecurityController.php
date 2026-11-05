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

/** CrowdSec: who is blocked, and manual bans. */
class SecurityController extends Controller
{
    public function index(AgentClient $agent): View
    {
        try {
            $decisions = $agent->call('crowdsec.decisions')['decisions'] ?? [];
            $metrics = $agent->call('crowdsec.metrics');
            $error = null;
        } catch (AgentException $e) {
            $decisions = [];
            $metrics = null;
            $error = $e->getMessage();
        }

        return view('admin.security.index', compact('decisions', 'metrics', 'error'));
    }

    public function ban(Request $request, AgentClient $agent): RedirectResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'max:64', function ($attr, $value, $fail) {
                if (! $this->validAddress($value)) {
                    $fail(__('Enter a valid IP address or range.'));
                }
            }],
            'duration' => ['required', Rule::in(['1h', '4h', '24h', '7d', '30d', '365d'])],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);
        try {
            $agent->call('crowdsec.ban', array_filter($data));
        } catch (AgentException $e) {
            return back()->withInput()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('security.ban', $data['address'], ['duration' => $data['duration']]);

        return back()->with('status', __(':address is blocked.', ['address' => $data['address']]));
    }

    public function unban(Request $request, AgentClient $agent): RedirectResponse
    {
        $data = $request->validate(['address' => ['required', 'string', 'max:64']]);
        abort_unless($this->validAddress($data['address']), 422);
        try {
            $agent->call('crowdsec.unban', $data);
        } catch (AgentException $e) {
            return back()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record('security.unban', $data['address']);

        return back()->with('status', __(':address is unblocked.', ['address' => $data['address']]));
    }

    private function validAddress(string $value): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $value, 2), 2, null);
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return $prefix === null || (ctype_digit($prefix) && (int) $prefix <= $max);
    }
}
