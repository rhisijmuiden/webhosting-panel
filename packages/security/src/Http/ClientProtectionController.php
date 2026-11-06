<?php

declare(strict_types=1);

namespace Rhis\Security\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\Security\LoginProtection;
use Rhis\Security\Models\SiteProtection;

/**
 * Customer: switch protection of their own website on or off, keep their
 * own allowlist (home, office) and see recent attacks.
 */
final class ClientProtectionController extends Controller
{
    public function __construct(private readonly LoginProtection $protection, private readonly AgentClient $agent) {}

    public function index(Request $request): View
    {
        $websites = $request->user()->customer->websites()->orderBy('domain')->get();
        $rows = SiteProtection::whereIn('website_id', $websites->pluck('id'))->get()->keyBy('website_id');

        return view('security::client.index', [
            'websites' => $websites,
            'protections' => $rows,
            'ip' => $request->ip(),
            'ipStatus' => $this->ipStatus((string) $request->ip()),
        ]);
    }

    public function show(Request $request, Website $website): View
    {
        $this->authorizeSite($request, $website);
        try {
            $activity = $this->agent->call('security.site_activity', ['domain' => $website->domain, 'days' => 7])['alerts'] ?? [];
            $error = null;
        } catch (AgentException $e) {
            $activity = [];
            $error = __('Recent activity could not be loaded.');
        }

        return view('security::client.show', [
            'website' => $website,
            'protection' => $this->protection->forSite($website),
            'activity' => $activity,
            'error' => $error,
            'ip' => $request->ip(),
            'ipStatus' => $this->ipStatus((string) $request->ip()),
            'max' => (int) config('security.client_allowlist_max'),
        ]);
    }

    public function update(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'wordpress' => ['required', 'boolean']]);
        $p = $this->protection->forSite($website);
        $p->fill(['enabled' => (bool) $data['enabled'], 'wordpress' => (bool) $data['wordpress']])->save();
        ActivityLog::record('security.site_protection', $website->domain, ['enabled' => $p->enabled, 'wordpress' => $p->wordpress]);

        return $this->applied(__('Protection settings saved.'));
    }

    public function allow(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $data = $request->validate([
            'address' => ['required', 'string', 'max:64', function ($attr, $value, $fail) {
                if (! LoginProtection::validNetwork($value, 24, 56)) {
                    $fail(__('Enter an IP address or a range of at most /24 (IPv4) or /56 (IPv6).'));
                }
            }],
            'comment' => ['nullable', 'string', 'max:60'],
        ]);
        $p = $this->protection->forSite($website);
        $list = collect($p->allowlist ?? []);
        $address = LoginProtection::normalise($data['address']);
        if ($list->contains('address', $address)) {
            return back()->withErrors(['address' => __(':address is already on the list.', ['address' => $address])]);
        }
        if ($list->count() >= (int) config('security.client_allowlist_max')) {
            return back()->withErrors(['address' => __('You can allowlist at most :max addresses per website.', ['max' => config('security.client_allowlist_max')])]);
        }
        $p->allowlist = $list->push(['address' => $address, 'comment' => (string) ($data['comment'] ?? '')])->values()->all();
        $p->save();
        ActivityLog::record('security.site_allowlist_added', $website->domain, ['address' => $address]);

        $redirect = $this->applied(__(':address is allowlisted for :domain.', ['address' => $address, 'domain' => $website->domain]));
        // Lift a block this site caused on that address (a mistyped password at home, say).
        try {
            $this->agent->call('security.site_release', ['domain' => $website->domain, 'address' => $address]);
        } catch (AgentException) {
            // The allowlist still applies to new attempts.
        }

        return $redirect;
    }

    public function disallow(Request $request, Website $website): RedirectResponse
    {
        $this->authorizeSite($request, $website);
        $address = (string) $request->validate(['address' => ['required', 'string', 'max:64']])['address'];
        $p = $this->protection->forSite($website);
        $p->allowlist = collect($p->allowlist ?? [])->reject(fn ($e) => $e['address'] === $address)->values()->all() ?: null;
        $p->save();
        ActivityLog::record('security.site_allowlist_removed', $website->domain, ['address' => $address]);

        return $this->applied(__(':address is removed from the allowlist.', ['address' => $address]));
    }

    private function applied(string $message): RedirectResponse
    {
        try {
            $this->protection->apply();
        } catch (AgentException) {
            return back()->with('status', $message)
                ->withErrors(['agent' => __('Saved, but the server could not apply it right now. Please try again in a minute, or contact support.')]);
        }

        return back()->with('status', $message);
    }

    /** @return array{blocked: bool, decisions: array}|null */
    private function ipStatus(string $ip): ?array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        try {
            return $this->agent->call('security.check_ip', ['address' => $ip]);
        } catch (AgentException) {
            return null;
        }
    }

    private function authorizeSite(Request $request, Website $website): void
    {
        abort_unless($website->customer_id === $request->user()->customer_id, 404);
    }
}
