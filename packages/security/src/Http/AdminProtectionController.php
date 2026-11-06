<?php

declare(strict_types=1);

namespace Rhis\Security\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\Security\LoginProtection;
use Rhis\Security\Models\SiteProtection;

/** Admin: thresholds per service, permanent block length, server-wide allowlist. */
final class AdminProtectionController extends Controller
{
    public function __construct(private readonly LoginProtection $protection) {}

    public function edit(): View
    {
        $sites = SiteProtection::query()->with('website:id,domain,customer_id', 'website.customer:id,name')
            ->where(fn ($q) => $q->where('enabled', false)->orWhere('wordpress', false)->orWhereNotNull('allowlist'))
            ->get()->filter(fn ($p) => $p->website && (! $p->enabled || ! $p->wordpress || $p->allowlist))
            ->sortBy('website.domain')->values();

        return view('security::admin.edit', [
            'services' => $this->protection->services(),
            'permanentDays' => $this->protection->permanentDays(),
            'allowlist' => $this->protection->allowlist(),
            'last' => $this->protection->lastApply(),
            'sites' => $sites,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = ['permanent_days' => ['required', 'integer', 'min:0', 'max:3650']];
        foreach (LoginProtection::SERVICES as $s) {
            $rules += [
                "services.$s.enabled" => ['sometimes', 'boolean'],
                "services.$s.temp_after" => ['required', 'integer', 'min:1', 'max:1000'],
                "services.$s.window_minutes" => ['required', 'integer', 'min:1', 'max:1440'],
                "services.$s.ban_minutes" => ['required', 'integer', 'min:1', 'max:525600'],
                "services.$s.perm_after" => ['required', 'integer', 'min:0', 'max:10000'],
                "services.$s.perm_window_hours" => ['required', 'integer', 'min:1', 'max:720'],
            ];
        }
        $data = $request->validate($rules);

        $services = [];
        $errors = [];
        foreach (LoginProtection::SERVICES as $s) {
            $in = $data['services'][$s];
            $services[$s] = [
                'enabled' => (bool) ($in['enabled'] ?? false),
                'temp_after' => (int) $in['temp_after'],
                'window_minutes' => (int) $in['window_minutes'],
                'ban_minutes' => (int) $in['ban_minutes'],
                'perm_after' => (int) $in['perm_after'],
                'perm_window_hours' => (int) $in['perm_window_hours'],
            ];
            if ($services[$s]['perm_after'] && $services[$s]['perm_after'] <= $services[$s]['temp_after']) {
                $errors["services.$s.perm_after"] = __('Must be higher than the number for a temporary block, or 0 for off.');
            }
        }
        if ($errors) {
            return back()->withInput()->withErrors($errors);
        }

        $this->protection->saveServices($services, (int) $data['permanent_days']);
        ActivityLog::record('security.protection_updated', null, ['services' => $services, 'permanent_days' => (int) $data['permanent_days']]);

        return $this->applied(__('Login protection saved.'));
    }

    public function apply(): RedirectResponse
    {
        return $this->applied(__('Settings sent to the server.'));
    }

    public function allow(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string', 'max:64', function ($attr, $value, $fail) {
                if (! LoginProtection::validNetwork($value)) {
                    $fail(__('Enter an IP address or a range of at most /16 (IPv4) or /48 (IPv6).'));
                }
            }],
            'comment' => ['nullable', 'string', 'max:120'],
        ]);
        $address = LoginProtection::normalise($data['address']);
        $list = collect($this->protection->allowlist());
        if ($list->contains('address', $address)) {
            return back()->withErrors(['address' => __(':address is already on the list.', ['address' => $address])]);
        }
        if ($list->count() >= 200) {
            return back()->withErrors(['address' => __('The allowlist is full (200 entries).')]);
        }
        $this->protection->saveAllowlist($list->push(['address' => $address, 'comment' => (string) ($data['comment'] ?? '')])->all());
        ActivityLog::record('security.allowlist_added', $address);

        return $this->applied(__(':address is trusted and unblocked.', ['address' => $address]));
    }

    public function disallow(Request $request): RedirectResponse
    {
        $address = (string) $request->validate(['address' => ['required', 'string', 'max:64']])['address'];
        $list = collect($this->protection->allowlist());
        $this->protection->saveAllowlist($list->reject(fn ($e) => $e['address'] === $address)->values()->all());
        ActivityLog::record('security.allowlist_removed', $address);

        return $this->applied(__(':address is no longer trusted.', ['address' => $address]));
    }

    private function applied(string $message): RedirectResponse
    {
        try {
            $result = $this->protection->apply();
        } catch (AgentException $e) {
            return back()->with('status', $message)
                ->withErrors(['agent' => __('Saved, but the server did not accept it yet: :error', ['error' => $e->getMessage()])]);
        }
        $redirect = back()->with('status', $message);

        return empty($result['warning']) ? $redirect : $redirect->withErrors(['agent' => $result['warning']]);
    }
}
