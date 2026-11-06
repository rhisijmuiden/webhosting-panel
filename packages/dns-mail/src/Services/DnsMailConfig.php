<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Services\Settings;
use Rhis\DnsMail\Mail\MailServerSettings;

/** Server-wide DNS/mail settings: the settings screen first, then config/env. */
final class DnsMailConfig
{
    public const KEYS = [
        'mail_host', 'ipv4', 'ipv6', 'relay_spf_includes', 'webmail_host', 'dmarc_rua', 'tlsrpt_rua',
        'm365_order_url', 'm365_price', 'default_quarantine_score', 'dane', 'cloudflare_account_id',
    ];

    public function __construct(private readonly Settings $settings)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get('dnsmail.' . $key);
        if ($value !== null && $value !== '') {
            return $value;
        }
        $config = match ($key) {
            'mail_host', 'ipv4', 'ipv6', 'webmail_host' => config('dns-mail.server.' . $key),
            'relay_spf_includes' => implode(',', (array) config('dns-mail.server.relay_spf_includes', [])),
            'dmarc_rua' => config('dns-mail.defaults.dmarc_rua'),
            'tlsrpt_rua' => config('dns-mail.defaults.tls_rpt'),
            'cloudflare_account_id' => config('dns-mail.cloudflare_account_id'),
            default => null,
        };

        return $config ?? $default;
    }

    public function defaultQuarantineScore(): int
    {
        $v = (int) $this->get('default_quarantine_score', 10);

        return $v >= 6 && $v <= 50 ? $v : 10;
    }

    public function daneEnabled(): bool
    {
        return (bool) $this->get('dane', false);
    }

    public function cloudflareToken(): string
    {
        return (string) ($this->settings->get('dnsmail.cloudflare_token') ?: config('dns-mail.cloudflare_token', ''));
    }

    public function hasCloudflare(): bool
    {
        return $this->cloudflareToken() !== '';
    }

    public function mailHost(): string
    {
        return (string) ($this->get('mail_host') ?: gethostname());
    }

    public function server(): MailServerSettings
    {
        $includes = array_values(array_filter(array_map('trim', explode(',', (string) $this->get('relay_spf_includes', '')))));

        return new MailServerSettings(
            mailHost: $this->mailHost(),
            ipv4: $this->get('ipv4') ?: null,
            ipv6: $this->get('ipv6') ?: null,
            relaySpfIncludes: $includes,
            webmailHost: $this->get('webmail_host') ?: null,
            brandName: (string) config('dns-mail.server.brand', 'RHIS'),
        );
    }
}
