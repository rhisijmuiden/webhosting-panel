<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Rhis\DnsMail\Cloudflare\CloudflareClient;
use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Services\DaneSync;
use Rhis\DnsMail\Services\DnsMailConfig;
use Rhis\DnsMail\Services\MailPusher;
use Throwable;

/** Cloudflare token and the server facts every domain's records use. */
class SettingsController extends Controller
{
    public function __construct(private readonly Settings $settings, private readonly DnsMailConfig $config)
    {
    }

    public function edit(): View
    {
        $values = [];
        foreach (DnsMailConfig::KEYS as $key) {
            $values[$key] = $this->config->get($key);
        }

        return view('dns-mail::admin.settings', [
            'values' => $values,
            'hasToken' => $this->config->hasCloudflare(),
            'daneOn' => $this->config->daneEnabled(),
            'daneRecord' => '_25._tcp.' . $this->config->mailHost(),
        ]);
    }

    public function update(Request $request, MailPusher $pusher): RedirectResponse
    {
        $host = 'regex:/^(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i';
        $data = $request->validate([
            'cloudflare_token' => ['nullable', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+$/'],
            'mail_host' => ['required', 'string', 'max:190', $host],
            'ipv4' => ['nullable', 'ipv4'],
            'ipv6' => ['nullable', 'ipv6'],
            'relay_spf_includes' => ['nullable', 'string', 'max:500', 'regex:/^[a-z0-9_.,\s-]*$/i'],
            'webmail_host' => ['nullable', 'string', 'max:190', $host],
            'dmarc_rua' => ['nullable', 'email', 'max:190'],
            'tlsrpt_rua' => ['nullable', 'email', 'max:190'],
            'm365_order_url' => ['nullable', 'url:https', 'max:300'],
            'm365_price' => ['nullable', 'string', 'max:60'],
            'default_quarantine_score' => ['nullable', 'integer', 'min:6', 'max:50'],
        ]);
        $data['dane'] = $this->config->daneEnabled() ? '1' : null; // changed with the DANE buttons only
        foreach (DnsMailConfig::KEYS as $key) {
            $this->settings->set('dnsmail.' . $key, $data[$key] ?? null);
        }
        if ($request->boolean('clear_token')) {
            $this->settings->set('dnsmail.cloudflare_token', null, encrypted: true);
        } elseif (filled($data['cloudflare_token'] ?? null)) {
            $this->settings->set('dnsmail.cloudflare_token', $data['cloudflare_token'], encrypted: true);
        }
        ActivityLog::record('settings.dnsmail_updated', $data['mail_host']);
        $warning = null;
        try {
            $pusher->push();
        } catch (Throwable $e) {
            $warning = __('The mail server was not updated: :error', ['error' => $e->getMessage()]);
        }

        return back()->with('status', __('DNS and mail settings saved.'))->with('warnings', array_filter([$warning]));
    }

    /** DANE: check, publish (and keep publishing daily) or withdraw the TLSA record. */
    public function dane(Request $request, DaneSync $dane): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', 'in:check,publish,withdraw']])['action'];
        try {
            if ($action === 'check') {
                $status = $dane->status();

                return back()->with('dane', [
                    'record' => $dane->recordName(),
                    'value' => $status['desired'][0]->content,
                    'dnssec' => $status['dnssec'],
                    'not_after' => $status['tlsa']['not_after'] ?? null,
                    'problems' => $status['problems'],
                ]);
            }
            $plan = $dane->apply($action === 'publish');
        } catch (Throwable $e) {
            return back()->withErrors(['dane' => $e->getMessage()]);
        }
        if ($plan->conflicts !== []) {
            return back()->withErrors(['dane' => __('A TLSA record at :name was not made by the panel. Remove it in Cloudflare first.', ['name' => $dane->recordName()])]);
        }
        $this->settings->set('dnsmail.dane', $action === 'publish' ? '1' : null);

        return back()->with('status', $action === 'publish'
            ? __('DANE is on. The panel checks the record every night.')
            : __('DANE record withdrawn.'));
    }

    public function test(HttpClient $http): RedirectResponse
    {
        if (!$this->config->hasCloudflare()) {
            return back()->withErrors(['cloudflare_token' => __('Save a token first.')]);
        }
        try {
            $client = new CloudflareClient($http, $this->config->cloudflareToken());
            $token = $client->verifyToken();
            $zones = $client->listZones();
        } catch (Throwable $e) {
            return back()->withErrors(['cloudflare_token' => __('Cloudflare refused the token: :error', ['error' => $e->getMessage()])]);
        }

        return back()->with('status', __('Connected to Cloudflare (token :status). :count zones found.', ['status' => $token['status'], 'count' => count($zones)]));
    }
}
