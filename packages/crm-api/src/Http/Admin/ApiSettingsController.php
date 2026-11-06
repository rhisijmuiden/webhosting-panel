<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\CrmApi\Jobs\DeliverWebhook;
use Rhis\CrmApi\Models\ApiToken;
use Rhis\CrmApi\Models\Webhook;
use Rhis\CrmApi\Models\WebhookDelivery;
use Symfony\Component\HttpFoundation\IpUtils;

/** Settings > CRM API: tokens, webhook endpoints and their deliveries. */
final class ApiSettingsController extends Controller
{
    public function index(): View
    {
        return view('crm-api::admin.index', [
            'tokens' => ApiToken::whereNull('revoked_at')->orderBy('name')->get(),
            'webhooks' => Webhook::orderBy('created_at')->get(),
            'deliveries' => WebhookDelivery::latest('created_at')->latest('id')->limit(25)->get(),
            'baseUrl' => url('/api/v1/crm'),
        ]);
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'allowed_ips' => ['nullable', 'string', 'max:1000'],
        ]);
        $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) ($data['allowed_ips'] ?? '')))));
        foreach ($ips as $ip) {
            if (! IpUtils::checkIp('127.0.0.1', $ip) && ! filter_var(explode('/', $ip)[0], FILTER_VALIDATE_IP)) {
                return back()->withInput()->withErrors(['allowed_ips' => __(':ip is not an IP address or range.', ['ip' => $ip])]);
            }
        }
        [$token, $plain] = ApiToken::issue($data['name'], $ips);
        ActivityLog::record('crm.token_created', $token->name, ['allowed_ips' => $ips]);

        return back()->with('new_token', $plain)->with('status', __('Token created.'));
    }

    public function revokeToken(ApiToken $token): RedirectResponse
    {
        $token->update(['revoked_at' => now()]);
        ActivityLog::record('crm.token_revoked', $token->name);

        return back()->with('status', __('Token revoked.'));
    }

    public function retry(WebhookDelivery $delivery): RedirectResponse
    {
        $delivery->update(['status' => 'pending', 'attempts' => 0]);
        DeliverWebhook::dispatch($delivery->id);

        return back()->with('status', __('Delivery queued again.'));
    }
}
