<?php

declare(strict_types=1);

namespace Rhis\WordPress\Mail\Http;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\WordPress\Mail\WebsiteMail;
use Rhis\WordPress\Models\WordPressInstall;

/** Website mail of one WordPress install; admins see every install, customers their own. */
abstract class WpMailController extends Controller
{
    /** 'admin' or 'client'. */
    protected string $area;

    public function __construct(protected readonly WebsiteMail $mail) {}

    abstract protected function authorizeInstall(Request $request, WordPressInstall $install): void;

    public function show(Request $request, WordPressInstall $install): View
    {
        $this->authorizeInstall($request, $install);
        $install->loadMissing('website');
        $setting = $this->mail->setting($install);
        $status = null;
        $agentError = null;
        try {
            $status = $this->mail->status($install);
        } catch (AgentException $e) {
            $agentError = $e->getMessage();
        }
        $domains = $this->mail->senderDomains($install);
        $sender = $setting->sender();
        $senderDomain = strtolower(substr(strrchr($sender, '@') ?: '@', 1));
        $siteTab = Route::has("{$this->area}.websites.show") ? route("{$this->area}.websites.show", [$install->website, 'tab' => 'mail']) : null;

        return view("wordpress::mail.{$this->area}", [
            'area' => $this->area, 'install' => $install, 'setting' => $setting, 'status' => $status, 'agentError' => $agentError,
            'domains' => $domains, 'sender' => $sender, 'senderDomain' => $senderDomain,
            'check' => $this->mail->domainCheck($senderDomain), 'smtpPlugins' => $this->mail->smtpPlugins($install),
            'canEdit' => $this->area === 'admin' || config('wp-mail.customer'), 'siteMailUrl' => $siteTab,
            'defaultTo' => (string) $request->user()->email,
        ]);
    }

    public function update(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        abort_unless($this->area === 'admin' || config('wp-mail.customer'), 403);
        $domains = $this->mail->senderDomains($install);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'from_local' => ['required_if:enabled,1', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+(\.[A-Za-z0-9!#$%&\'*+\/=?^_`{|}~-]+)*$/'],
            'from_domain' => ['required_if:enabled,1', 'nullable', Rule::in($domains)],
            'from_name' => ['nullable', 'string', 'max:120', 'not_regex:/[\r\n]/'],
            'force_from' => ['sometimes', 'boolean'],
        ]);
        $setting = $this->mail->setting($install);
        $enabled = (bool) $data['enabled'];
        if ($enabled) {
            $email = strtolower($data['from_local'].'@'.$data['from_domain']);
            $default = config('wp-mail.local_part', 'noreply').'@'.strtolower($install->website->domain);
            $setting->fill([
                'from_email' => $email === $default ? null : $email,
                'from_name' => trim((string) ($data['from_name'] ?? '')) ?: null,
                'force_from' => (bool) ($data['force_from'] ?? false),
            ]);
        }
        $setting->fill(['enabled' => $enabled, 'reason' => null]);
        try {
            $this->mail->apply($setting);
        } catch (AgentException $e) {
            if ($enabled) {
                $setting->fill(['enabled' => false])->save();
            }

            return back()->withInput()->withErrors(['agent' => $e->getMessage()]);
        }
        ActivityLog::record($enabled ? 'wordpress.mail_on' : 'wordpress.mail_off', $install->label(),
            $enabled ? ['from' => $setting->sender()] : [], $install->website->customer_id);

        return back()->with('status', $enabled
            ? __('WordPress now sends its mail through this server as :from.', ['from' => $setting->sender()])
            : __('WordPress sends its mail the way it did before again.'));
    }

    public function test(Request $request, WordPressInstall $install): RedirectResponse
    {
        $this->authorizeInstall($request, $install);
        abort_unless($this->area === 'admin' || config('wp-mail.customer'), 403);
        $data = $request->validate(['to' => ['required', 'email:rfc', 'max:254']]);
        $setting = $this->mail->setting($install);
        if (! $setting->exists) {
            $setting->save();
        }
        $out = $this->mail->test($setting, $data['to']);
        ActivityLog::record('wordpress.mail_test', $install->label(), ['to' => $data['to'], 'ok' => (bool) ($out['ok'] ?? false)],
            $install->website->customer_id);

        return ($out['ok'] ?? false)
            ? back()->with('status', __('Test message sent to :to.', ['to' => $data['to']]))
            : back()->withErrors(['test' => $setting->last_test_error]);
    }
}
