<?php

declare(strict_types=1);

namespace Rhis\WordPress\Mail;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Collection;
use Rhis\WordPress\Models\WordPressInstall;

/**
 * WordPress mail through the mail server of this server (agent wp.mail_*,
 * mu-plugin whp-mail). One WpMailSetting per install holds what the panel wants;
 * the agent writes it into the install.
 */
final class WebsiteMail
{
    public function __construct(private readonly AgentClient $agent) {}

    public function setting(WordPressInstall $install): WpMailSetting
    {
        $setting = WpMailSetting::firstOrNew(['wordpress_install_id' => $install->id]);
        $setting->setRelation('install', $install);

        return $setting;
    }

    /** What the agent sees in the install right now (null when the agent is unreachable). */
    public function status(WordPressInstall $install): array
    {
        return $this->agent->call('wp.mail_status', $install->target(), 30);
    }

    /**
     * Domains the website may send from: its own domain and the other mail
     * domains of the same customer that have a DKIM key on this server.
     *
     * @return list<string>
     */
    public function senderDomains(WordPressInstall $install): array
    {
        $site = $install->website;
        $domains = collect([strtolower($site->domain)]);
        $class = \Rhis\DnsMail\Models\MailDomain::class;
        if (class_exists($class)) {
            $domains = $domains->merge($class::query()
                ->where(fn ($q) => $q->where('website_id', $site->id)
                    ->orWhere(fn ($q) => $site->customer_id ? $q->where('customer_id', $site->customer_id) : $q->whereRaw('1 = 0')))
                ->whereNotNull('dkim_public_key')
                ->pluck('domain')->map(fn ($d) => strtolower($d)));
        }

        return $domains->unique()->values()->all();
    }

    /**
     * How mail for a sender domain fares at the receiving end: DKIM key, this
     * server in SPF, DNS managed by the panel. Null without the mail module.
     */
    public function domainCheck(string $domain): ?array
    {
        $class = \Rhis\DnsMail\Models\MailDomain::class;
        if (! class_exists($class)) {
            return null;
        }
        $d = $class::where('domain', strtolower($domain))->first();
        if (! $d) {
            return ['domain' => $domain, 'known' => false, 'dkim' => false, 'spf' => false, 'dns' => 'neutral', 'website_id' => null];
        }

        return [
            'domain' => $d->domain, 'known' => true, 'dkim' => $d->dkim_public_key !== null,
            // Mail hosted here, or "websites on this server send mail" for Microsoft 365 / external.
            'spf' => $d->hasLocalMail() || $d->server_sends,
            'dns' => $d->dns_managed ? $d->dnsPill() : 'neutral', 'dns_managed' => (bool) $d->dns_managed,
            'website_id' => $d->website_id,
        ];
    }

    /** Active plugins that send mail their own way. */
    public function smtpPlugins(WordPressInstall $install): Collection
    {
        $known = config('wp-mail.smtp_plugins', []);

        return collect($install->plugins ?? [])
            ->filter(fn ($p) => in_array($p['name'] ?? '', $known, true) && in_array($p['status'] ?? '', ['active', 'active-network', 'must-use'], true))
            ->values();
    }

    /** Write the setting into the install (or take the plugin out); errors are stored on the setting and thrown. */
    public function apply(WpMailSetting $setting): array
    {
        $install = $setting->install;
        $params = $install->target() + ['enabled' => (bool) $setting->enabled];
        if ($setting->enabled) {
            $params += [
                'from_email' => $setting->sender(),
                'from_name' => (string) ($setting->from_name ?? ''),
                'force_from' => (bool) $setting->force_from,
                'domains' => $this->senderDomains($install),
            ];
        }
        try {
            $status = $this->agent->call('wp.mail_apply', $params, 90);
        } catch (AgentException $e) {
            $setting->fill(['apply_error' => $e->getMessage()])->save();
            throw $e;
        }
        $setting->fill(['applied_at' => now(), 'apply_error' => null])->save();

        return $status;
    }

    public function test(WpMailSetting $setting, string $to): array
    {
        $install = $setting->install;
        try {
            $out = $this->agent->call('wp.mail_test', $install->target() + [
                'to' => $to,
                'subject' => __('Test message from :site', ['site' => $install->label()]),
                'body' => __("This is a test message from the WordPress website :site, sent from the hosting panel.\n\nIt went through WordPress' own mail function, so contact forms and web shop mail take the same route.", ['site' => $install->label()]),
            ], 120);
        } catch (AgentException $e) {
            $out = ['ok' => false, 'error' => $e->getMessage()];
        }
        $setting->fill([
            'last_test_to' => $to, 'last_test_at' => now(), 'last_test_ok' => (bool) ($out['ok'] ?? false),
            'last_test_error' => ($out['ok'] ?? false) ? null : (string) (($out['error'] ?? '') ?: __('WordPress could not send the message.')),
            'last_test_queue_id' => $out['queue_id'] ?? null,
        ])->save();

        return $out;
    }

    /**
     * Switch a new install over (the 'auto' setting): not on a staging copy, not
     * while an SMTP plugin is active, not when the server has no website mail.
     * Returns the stored setting.
     */
    public function adopt(WordPressInstall $install): WpMailSetting
    {
        $setting = $this->setting($install);
        // Decided before; only "the server had no website mail yet" is looked at again.
        if ($setting->exists && ! ($setting->reason === 'unavailable' && ! $setting->enabled)) {
            return $setting;
        }
        $status = $this->status($install);
        $setting->enabled = false;
        if (! empty($status['enabled'])) {
            // Already on (e.g. set up before this panel knew the install): keep what is there.
            $setting->fill(['enabled' => true, 'from_email' => $status['from_email'] ?? null,
                'from_name' => ($status['from_name'] ?? '') ?: null, 'force_from' => (bool) ($status['force_from'] ?? true)]);
            $setting->save();

            return $setting;
        }
        $reason = match (true) {
            (bool) ($status['staging'] ?? false) => 'staging',
            ! ($status['smtp'] ?? false) => 'unavailable',
            $this->smtpPlugins($install)->isNotEmpty() => 'smtp_plugin',
            default => null,
        };
        $setting->reason = $reason;
        $setting->save();
        if ($reason === null && config('wp-mail.auto')) {
            $setting->enabled = true;
            try {
                $this->apply($setting);
            } catch (AgentException) {
                $setting->fill(['enabled' => false])->save();
            }
        }

        return $setting;
    }
}
