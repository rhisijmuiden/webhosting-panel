<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Rhis\DnsMail\ClientSetup\AppleMobileconfig;
use Rhis\DnsMail\ClientSetup\MailClientSettings;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Mail\MailProvider;
use Rhis\DnsMail\Mail\SpfRecord;
use Rhis\DnsMail\Models\MailAlias;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Models\MailSenderRule;
use Rhis\DnsMail\Models\Mailbox;
use Rhis\DnsMail\Services\DnsMailConfig;
use Rhis\DnsMail\Services\DnsOnboarder;
use Rhis\DnsMail\Services\DnsSync;
use Rhis\DnsMail\Services\DomainProvisioner;
use Rhis\DnsMail\Services\DomainRecords;
use Rhis\DnsMail\Services\ExternalDnsCheck;
use Throwable;

/**
 * Everything on a domain's page, shared by the admin panel and the client
 * portal. Subclasses say which domains the user may see and the route prefix.
 */
abstract class DomainActions extends Controller
{
    public const TABS = ['mail', 'mailboxes', 'spam', 'dns'];

    public const OWN_RECORD_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];

    public function __construct(
        protected readonly DnsMailConfig $config,
        protected readonly DomainRecords $records,
        protected readonly DnsSync $dns,
        protected readonly DomainProvisioner $provisioner,
    ) {
    }

    abstract protected function area(): string; // admin | client

    abstract protected function findDomain(int|string $id): MailDomain;

    protected function route(string $name, mixed $params = []): string
    {
        return route($this->area() . '.mail.' . $name, $params);
    }

    public function show(Request $request, int|string $mailDomain): View
    {
        $domain = $this->findDomain($mailDomain)->load(['customer', 'website.subscription.package', 'aliasOf.website.subscription.package', 'mailboxes', 'aliases', 'senderRules', 'onboarding']);
        $tab = in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'mail';
        $settings = $this->records->settings($domain);
        $profile = $this->records->profile();
        $data = [
            'area' => $this->area(),
            'domain' => $domain,
            'tab' => $tab,
            'spf' => $profile->spf($settings),
            'dmarc' => $settings->dmarc,
            'mailRecords' => $this->records->mail($domain),
            'allRecords' => $this->records->all($domain),
            'mailHost' => $this->config->mailHost(),
            'webmailHost' => $this->config->get('webmail_host'),
            'hasCloudflare' => $this->config->hasCloudflare(),
            'm365OrderUrl' => $this->config->get('m365_order_url'),
            'm365Price' => $this->config->get('m365_price'),
            'mailboxLimit' => self::mailboxLimit($domain),
            'mailboxCount' => $domain->mailboxes()->count(),
            'inspect' => null,
            'adopt' => false,
        ];
        $data['quarantine'] = null;
        $data['quarantineError'] = null;
        $data['defaultQuarantineScore'] = $this->config->defaultQuarantineScore();
        if ($tab === 'spam' && $domain->hasLocalMail() && $domain->mailboxes->isNotEmpty()) {
            try {
                $data['quarantine'] = app(AgentClient::class)->call('mail.quarantine_list', ['domain' => $domain->domain], 120);
            } catch (Throwable $e) {
                $data['quarantineError'] = $e->getMessage();
            }
        }
        $data['onboarding'] = $domain->onboarding;
        if ($tab === 'dns' && $domain->onboarding?->isActive() && $domain->onboarding->seen_at === null && $this->area() === 'client') {
            $domain->onboarding->update(['seen_at' => now()]);
        }
        if ($tab === 'dns' && $domain->dns_managed && $data['hasCloudflare']) {
            $data['adopt'] = $request->boolean('adopt');
            $data['inspect'] = $this->dns->inspect($domain, $data['adopt']);
        }

        return view('dns-mail::domain.show', $data);
    }

    public function updateMail(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $data = $request->validate([
            'provider' => ['required', Rule::enum(MailProvider::class)],
            'spf_extra' => ['nullable', 'string', 'max:300'],
            'spf_all' => ['required', Rule::in(['-', '~', '?'])],
            'dmarc_policy' => ['required', Rule::in(MailDomain::DMARC_POLICIES)],
            'dmarc_rua' => ['nullable', 'email', 'max:190'],
            'mta_sts_mode' => ['nullable', Rule::in(MailDomain::STS_MODES)],
            'm365_tenant' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9-]+(\.onmicrosoft\.com)?$/i'],
            'm365_verification' => ['nullable', 'string', 'max:60', 'regex:/^MS=ms\d{6,12}$/'],
            'm365_mx_host' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'm365_dkim_selector1' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9._-]+\.[a-z]{2,}$/i'],
            'm365_dkim_selector2' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9._-]+\.[a-z]{2,}$/i'],
            'bimi_logo_url' => ['nullable', 'url:https', 'max:250', 'regex:/^[^\s;"]+$/'],
            'bimi_vmc_url' => ['nullable', 'url:https', 'max:250', 'regex:/^[^\s;"]+$/'],
        ]);
        if (filled($data['spf_extra'] ?? null)) {
            try {
                SpfRecord::parse('v=spf1 ' . $data['spf_extra']);
                foreach (preg_split('/\s+/', trim($data['spf_extra'])) as $m) {
                    if (!preg_match('/^[+\-~?]?(include:|ip4:|ip6:|a(:|$)|mx(:|$)|exists:)[a-z0-9.:\/_-]*$/i', $m)) {
                        throw new \InvalidArgumentException($m);
                    }
                }
            } catch (Throwable) {
                throw ValidationException::withMessages(['spf_extra' => __('Use SPF mechanisms such as include:mailchimp.com or ip4:192.0.2.1.')]);
            }
        }
        $data['server_sends'] = $request->boolean('server_sends');
        $data['m365_intune'] = $request->boolean('m365_intune');
        if ($this->area() === 'admin') {
            $data['dns_managed'] = $request->boolean('dns_managed');
            $data['proxied'] = $request->boolean('proxied');
        }
        $switched = $domain->provider !== $data['provider'];
        $domain->update($data);
        ActivityLog::record('mail.domain_updated', $domain->domain, ['provider' => $domain->provider], $domain->customer_id);

        $warnings = $this->provisioner->afterChange($domain, withDkim: true);
        $status = $switched
            ? __('Mail for :domain now goes to :provider.', ['domain' => $domain->domain, 'provider' => __($this->providerLabel($domain->providerEnum()))])
            : __('Mail settings saved.');

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'mail']))
            ->with('status', $status)->with('warnings', $warnings);
    }

    public function dkim(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        try {
            $this->provisioner->ensureDkim($domain, replace: $request->boolean('replace'));
        } catch (Throwable $e) {
            return back()->withErrors(['dkim' => __('DKIM key not created: :error', ['error' => $e->getMessage()])]);
        }
        ActivityLog::record('mail.dkim_created', $domain->domain, [], $domain->customer_id);
        $warnings = $this->provisioner->afterChange($domain->fresh());

        return back()->with('status', __('DKIM key ready.'))->with('warnings', $warnings);
    }

    public function applyDns(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        try {
            $plan = $this->dns->apply($domain, adopt: $request->boolean('adopt'));
        } catch (Throwable $e) {
            return back()->withErrors(['dns' => $e->getMessage()]);
        }

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'dns']))
            ->with('status', trans_choice(':count DNS change published.|:count DNS changes published.', count($plan->changes), ['count' => count($plan->changes)]));
    }

    public function storeRecord(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $record = $this->ownRecordFromRequest($request, $domain);
        try {
            $this->dns->addOwnRecord($domain, $record);
        } catch (Throwable $e) {
            return back()->withErrors(['content' => $e->getMessage()])->withInput();
        }

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'dns']))->with('status', __('Record added.'));
    }

    public function updateRecord(Request $request, int|string $mailDomain, string $record): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $new = $this->ownRecordFromRequest($request, $domain, 'edit');
        try {
            $this->dns->updateOwnRecord($domain, $record, $new);
        } catch (Throwable $e) {
            return back()->withErrors(['dns' => $e->getMessage()]);
        }

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'dns']))->with('status', __('Record saved.'));
    }

    /** Cloudflare proxy for the website records (apex, www and aliases). Mail records stay DNS only. */
    public function updateProxy(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $domain->update(['proxied' => $request->boolean('proxied')]);
        ActivityLog::record('dns.proxy_changed', $domain->domain, ['proxied' => $domain->proxied], $domain->customer_id);
        $warnings = $this->provisioner->afterChange($domain, withDkim: false);

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'dns']))
            ->with('status', $domain->proxied ? __('The website now goes through the Cloudflare proxy.') : __('The website now points straight at the server (DNS only).'))
            ->with('warnings', $warnings);
    }

    /** Validate the add/edit record form into a record of this domain. */
    private function ownRecordFromRequest(Request $request, MailDomain $domain, string $bag = 'default'): DnsRecord
    {
        $data = $request->validateWithBag($bag, [
            'type' => ['required', Rule::in(self::OWN_RECORD_TYPES)],
            'name' => ['nullable', 'string', 'max:190', 'regex:/^(@|[a-z0-9_*]([a-z0-9_.-]*[a-z0-9_])?)$/i'],
            'content' => ['required', 'string', 'max:2048'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'ttl' => ['nullable', 'integer', Rule::in([1, 60, 300, 3600, 86400])],
        ]);
        $name = blank($data['name'] ?? null) || $data['name'] === '@' ? $domain->domain : strtolower($data['name']) . '.' . $domain->domain;
        $content = trim($data['content']);
        $valid = match ($data['type']) {
            'A' => (bool) filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4),
            'AAAA' => (bool) filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6),
            'CNAME', 'MX' => (bool) preg_match('/^[a-z0-9_.-]+\.[a-z]{2,}\.?$/i', $content),
            'TXT' => !str_contains($content, "\n"),
        };
        if (!$valid) {
            throw ValidationException::withMessages(['content' => __('This value does not fit a :type record.', ['type' => $data['type']])])->errorBag($bag);
        }
        $proxied = in_array($data['type'], DnsRecord::PROXIABLE_TYPES, true) ? $request->boolean('proxied') : null;
        if ($proxied && !DnsRecord::mayProxy($data['type'], $name, $domain->domain)) {
            throw ValidationException::withMessages(['proxied' => __(':name carries mail or other non-web traffic and cannot go through the Cloudflare proxy.', ['name' => $data['name'] ?: '@'])])->errorBag($bag);
        }

        return new DnsRecord(
            $data['type'], $name, $content, (int) ($data['ttl'] ?? 1),
            proxied: $proxied,
            priority: $data['type'] === 'MX' ? (int) ($data['priority'] ?? 10) : null,
        );
    }

    /** The DNS wizard: move the domain's DNS to the RHIS Cloudflare account. */
    public function connect(int|string $mailDomain): View
    {
        $domain = $this->findDomain($mailDomain)->load(['onboarding', 'customer']);
        $onboarding = $domain->onboarding;
        if ($onboarding?->isActive() && $onboarding->seen_at === null && $this->area() === 'client') {
            $onboarding->update(['seen_at' => now()]);
        }

        return view('dns-mail::domain.connect', [
            'area' => $this->area(),
            'domain' => $domain,
            'onboarding' => $onboarding,
            'hasCloudflare' => $this->config->hasCloudflare(),
        ]);
    }

    public function startConnect(int|string $mailDomain, DnsOnboarder $onboarder): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        try {
            $result = $onboarder->start($domain);
        } catch (Throwable $e) {
            return back()->withErrors(['connect' => $e->getMessage()]);
        }

        return redirect()->to($this->route('domains.connect', $domain->id))
            ->with('status', $result['onboarding']->isActive()
                ? __('Your DNS is already running on RHIS Cloudflare.')
                : __(':domain is ready at RHIS Cloudflare. Now change the nameservers at your registrar.', ['domain' => $domain->domain]))
            ->with('warnings', $result['warnings']);
    }

    public function checkConnect(int|string $mailDomain, DnsOnboarder $onboarder): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain)->load('onboarding');
        $o = $domain->onboarding;
        if ($o === null) {
            return redirect()->to($this->route('domains.connect', $domain->id));
        }
        if ($o->last_checked_at !== null && $o->last_checked_at->diffInSeconds(now()) < 20) {
            return back()->with('status', __('Just checked. Try again in a moment.'));
        }
        try {
            $live = $onboarder->check($o, force: true);
        } catch (Throwable $e) {
            return back()->withErrors(['connect' => $e->getMessage()]);
        }
        $o->refresh();

        return back()->with('status', match (true) {
            $live => __('Done! DNS for :domain now runs on RHIS Cloudflare.', ['domain' => $domain->domain]),
            $o->nameserversMatch() => __('The new nameservers are visible. Cloudflare confirms this within a few minutes; we keep checking.'),
            default => __('The nameservers have not changed yet. A change at the registrar can take a few hours; we keep checking and mail you.'),
        });
    }

    /** Domains with DNS elsewhere: look up every needed record now. */
    public function checkExternal(int|string $mailDomain, ExternalDnsCheck $check): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain)->load('website');
        $result = $check->run($domain);

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'dns']))
            ->with($result['ok'] ? 'status' : 'warning', $result['ok'] ? __('All DNS records are set correctly.') : __('Some DNS records are still missing or wrong. See the list below.'));
    }

    public function destroyRecord(int|string $mailDomain, string $record): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        try {
            $this->dns->deleteOwnRecord($domain, $record);
        } catch (Throwable $e) {
            return back()->withErrors(['dns' => $e->getMessage()]);
        }

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'dns']))->with('status', __('Record removed.'));
    }

    public function storeMailbox(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain)->load('customer', 'website.subscription.package');
        if (!$domain->hasLocalMail()) {
            return back()->withErrors(['local_part' => __('Mailboxes can only be added when mail for this domain is hosted here.')]);
        }
        $data = $request->validate([
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9](?:[a-z0-9._+-]*[a-z0-9])?$/i',
                Rule::unique('mailboxes')->where('mail_domain_id', $domain->id),
                Rule::unique('mail_aliases')->where('mail_domain_id', $domain->id)],
            'display_name' => ['nullable', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:10', 'max:200', 'confirmed'],
            'quota_mb' => ['required', 'integer', 'min:0', 'max:1048576'],
        ], [], ['local_part' => __('address')]);
        $limit = self::mailboxLimit($domain);
        $count = $domain->mailboxes()->count();
        if ($limit !== null && $count >= $limit) {
            return back()->withErrors(['local_part' => __('The hosting package allows :count mailboxes. Remove one or upgrade the package.', ['count' => $limit])]);
        }
        $box = $domain->mailboxes()->create([
            'local_part' => strtolower($data['local_part']),
            'display_name' => $data['display_name'] ?? null,
            'password_hash' => Mailbox::hashPassword($data['password']),
            'quota_mb' => $data['quota_mb'],
        ]);
        ActivityLog::record('mail.mailbox_created', $box->local_part . '@' . $domain->domain, [], $domain->customer_id);

        return $this->mailboxesRedirect($domain, __('Mailbox :address created.', ['address' => $box->local_part . '@' . $domain->domain]));
    }

    public function updateMailbox(Request $request, int|string $mailDomain, int $mailbox): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $box = $domain->mailboxes()->findOrFail($mailbox);
        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'min:10', 'max:200', 'confirmed'],
            'quota_mb' => ['required', 'integer', 'min:0', 'max:1048576'],
            'send_as' => ['nullable', 'string', 'max:1000'],
        ]);
        $sendAs = array_values(array_unique(array_filter(array_map(
            fn ($a) => strtolower(trim($a)),
            preg_split('/[\s,]+/', (string) ($data['send_as'] ?? '')),
        ))));
        foreach ($sendAs as $addr) {
            if (!filter_var($addr, FILTER_VALIDATE_EMAIL) || !str_ends_with($addr, '@' . $domain->domain)) {
                throw ValidationException::withMessages(['send_as' => __('Only addresses at :domain.', ['domain' => $domain->domain])]);
            }
        }
        $box->fill([
            'display_name' => $data['display_name'] ?? null,
            'quota_mb' => $data['quota_mb'],
            'enabled' => $request->boolean('enabled'),
            'send_as' => $sendAs,
        ]);
        if (filled($data['password'] ?? null)) {
            $box->password_hash = Mailbox::hashPassword($data['password']);
        }
        $box->save();
        ActivityLog::record('mail.mailbox_updated', $box->local_part . '@' . $domain->domain, [], $domain->customer_id);

        return $this->mailboxesRedirect($domain, __('Mailbox saved.'));
    }

    public function destroyMailbox(int|string $mailDomain, int $mailbox): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $box = $domain->mailboxes()->findOrFail($mailbox);
        $address = $box->local_part . '@' . $domain->domain;
        $box->delete();
        ActivityLog::record('mail.mailbox_deleted', $address, [], $domain->customer_id);

        return $this->mailboxesRedirect($domain, __('Mailbox :address removed. Its mail stays on the server until it is purged.', ['address' => $address]));
    }

    public function storeAlias(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $data = $request->validate([
            'local_part' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9](?:[a-z0-9._+-]*[a-z0-9])?$/i',
                Rule::unique('mailboxes')->where('mail_domain_id', $domain->id)],
            'targets' => ['required', 'string', 'max:2000'],
        ]);
        $local = strtolower((string) ($data['local_part'] ?? ''));
        $targets = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', strtolower($data['targets']))))));
        foreach ($targets as $t) {
            if (!filter_var($t, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['targets' => __(':address is not a valid address.', ['address' => $t])]);
            }
        }
        MailAlias::updateOrCreate(['mail_domain_id' => $domain->id, 'local_part' => $local], ['targets' => $targets]);
        ActivityLog::record('mail.alias_saved', ($local ?: '*') . '@' . $domain->domain, [], $domain->customer_id);

        return $this->mailboxesRedirect($domain, __('Forwarding saved.'));
    }

    public function destroyAlias(int|string $mailDomain, int $alias): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $domain->aliases()->findOrFail($alias)->delete();

        return $this->mailboxesRedirect($domain, __('Forwarding removed.'));
    }

    public function mobileconfig(int|string $mailDomain, int $mailbox): Response
    {
        $domain = $this->findDomain($mailDomain);
        $box = $domain->mailboxes()->findOrFail($mailbox);
        $settings = new MailClientSettings($box->local_part . '@' . $domain->domain, $this->config->mailHost(), (string) $box->display_name, (string) config('dns-mail.server.brand', 'RHIS'));
        $plist = AppleMobileconfig::plist($settings, (string) config('dns-mail.server.brand', 'RHIS'));
        $body = $plist;
        $cert = config('dns-mail.profile_signing.cert');
        $key = config('dns-mail.profile_signing.key');
        if ($cert && $key && is_readable($cert) && is_readable($key)) {
            $body = AppleMobileconfig::sign($plist, $cert, $key, config('dns-mail.profile_signing.chain')) ?? $plist;
        }

        return response($body, 200, [
            'Content-Type' => 'application/x-apple-aspen-config',
            'Content-Disposition' => 'attachment; filename="' . $domain->domain . '-' . $box->local_part . '.mobileconfig"',
        ]);
    }

    public function updateSpam(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $data = $request->validate([
            'quarantine' => ['required', Rule::in(['on', 'off'])],
            'spam_quarantine_score' => ['required_if:quarantine,on', 'nullable', 'integer', 'min:6', 'max:50'],
        ]);
        $domain->update([
            'spam_quarantine_score' => $data['quarantine'] === 'on' ? (int) $data['spam_quarantine_score'] : null,
            'spam_junk' => $request->boolean('spam_junk'),
        ]);
        ActivityLog::record('mail.spam_updated', $domain->domain, ['score' => $domain->spam_quarantine_score], $domain->customer_id);

        return $this->spamRedirect($domain, __('Spam settings saved.'));
    }

    public function storeSenderRule(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $data = $request->validate([
            'type' => ['required', Rule::in(MailSenderRule::TYPES)],
            'value' => ['required', 'string', 'max:190'],
        ]);
        $value = self::senderValue($data['value'])
            ?? throw ValidationException::withMessages(['value' => __('Enter an address (jan@example.com) or a domain (example.com).')]);
        if ($domain->senderRules()->count() >= 1000) {
            throw ValidationException::withMessages(['value' => __('A domain can have at most 1000 senders on its lists.')]);
        }
        MailSenderRule::updateOrCreate(['mail_domain_id' => $domain->id, 'value' => $value], ['type' => $data['type']]);
        ActivityLog::record('mail.sender_rule_saved', $domain->domain, ['type' => $data['type'], 'value' => $value], $domain->customer_id);

        return $this->spamRedirect($domain, $data['type'] === 'allow'
            ? __(':sender is always delivered to the inbox.', ['sender' => $value])
            : __('Mail from :sender now goes to Junk.', ['sender' => $value]));
    }

    public function destroySenderRule(int|string $mailDomain, int $rule): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain);
        $domain->senderRules()->findOrFail($rule)->delete();

        return $this->spamRedirect($domain, __('Sender removed from the list.'));
    }

    public function releaseMessage(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain)->load('mailboxes');
        $data = $request->validate([
            'user' => ['required', 'email', Rule::in($this->mailboxAddresses($domain))],
            'uid' => ['required', 'integer', 'min:1'],
            'sender' => ['nullable', 'string', 'max:300'],
        ]);
        try {
            app(AgentClient::class)->call('mail.quarantine_release', ['domain' => $domain->domain, 'user' => $data['user'], 'uid' => (int) $data['uid'], 'learn' => true], 60);
        } catch (Throwable $e) {
            return back()->withErrors(['quarantine' => __('The message was not released: :error', ['error' => $e->getMessage()])]);
        }
        ActivityLog::record('mail.quarantine_released', $data['user'], ['uid' => (int) $data['uid']], $domain->customer_id);
        $status = __('Message delivered to the inbox of :user.', ['user' => $data['user']]);
        $sender = $request->boolean('allow_sender') ? self::senderValue(self::addressFrom((string) ($data['sender'] ?? ''))) : null;
        if ($sender !== null) {
            MailSenderRule::updateOrCreate(['mail_domain_id' => $domain->id, 'value' => $sender], ['type' => 'allow']);
            $status .= ' ' . __(':sender is always delivered to the inbox.', ['sender' => $sender]);

            return $this->spamRedirect($domain, $status);
        }

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'spam']))->with('status', $status);
    }

    public function deleteMessages(Request $request, int|string $mailDomain): RedirectResponse
    {
        $domain = $this->findDomain($mailDomain)->load('mailboxes');
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:500'],
            'messages.*' => ['string', 'regex:/^[^\s|]+@[^\s|]+\|\d{1,10}$/'],
        ]);
        $allowed = $this->mailboxAddresses($domain);
        $byUser = [];
        foreach ($data['messages'] as $m) {
            [$user, $uid] = explode('|', strtolower($m), 2);
            if (in_array($user, $allowed, true)) {
                $byUser[$user][] = (int) $uid;
            }
        }
        $deleted = 0;
        try {
            foreach ($byUser as $user => $uids) {
                $deleted += (int) (app(AgentClient::class)->call('mail.quarantine_delete', ['domain' => $domain->domain, 'user' => $user, 'uids' => $uids, 'learn' => true], 120)['deleted'] ?? 0);
            }
        } catch (Throwable $e) {
            return back()->withErrors(['quarantine' => __('Not all messages were deleted: :error', ['error' => $e->getMessage()])]);
        }
        ActivityLog::record('mail.quarantine_deleted', $domain->domain, ['count' => $deleted], $domain->customer_id);

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'spam']))
            ->with('status', trans_choice(':count message deleted.|:count messages deleted.', $deleted, ['count' => $deleted]));
    }

    /** "Name <jan@example.com>" -> "jan@example.com" */
    public static function addressFrom(string $header): string
    {
        return preg_match('/<([^<>\s]+@[^<>\s]+)>/', $header, $m) ? $m[1] : trim($header);
    }

    /** Normalised allow/block entry: an address, or a domain (also from "@example.com"); null when invalid. */
    public static function senderValue(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (str_starts_with($value, '@')) {
            $value = substr($value, 1);
        }
        if (str_contains($value, '@')) {
            return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
        }

        return preg_match('/^(?=.{1,253}$)(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/', $value) ? $value : null;
    }

    /** @return list<string> */
    protected function mailboxAddresses(MailDomain $domain): array
    {
        return $domain->mailboxes->map(fn (Mailbox $b) => $b->local_part . '@' . $domain->domain)->all();
    }

    protected function spamRedirect(MailDomain $domain, string $status): RedirectResponse
    {
        $warnings = $this->provisioner->afterChange($domain, syncDns: false);

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'spam']))
            ->with('status', $status)->with('warnings', $warnings);
    }

    protected function mailboxesRedirect(MailDomain $domain, string $status): RedirectResponse
    {
        $warnings = $this->provisioner->afterChange($domain, syncDns: false);

        return redirect()->to($this->route('domains.show', [$domain->id, 'tab' => 'mailboxes']))
            ->with('status', $status)->with('warnings', $warnings);
    }

    public static function providerLabel(MailProvider $p): string
    {
        return match ($p) {
            MailProvider::Rhis => 'RHIS Mail (this server)',
            MailProvider::Microsoft365 => 'Microsoft 365',
            MailProvider::External => 'Other provider',
        };
    }

    /** Mailboxes the package of the domain's website allows (an alias domain uses its main domain's website; null = unlimited). */
    protected static function mailboxLimit(MailDomain $domain): ?int
    {
        $website = $domain->website ?? $domain->aliasOf?->website;

        return $website?->package?->max_mailboxes;
    }
}
