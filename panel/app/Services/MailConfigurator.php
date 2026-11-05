<?php

namespace App\Services;

use Illuminate\Support\Facades\Config;

/**
 * Applies the SMTP server configured in the panel to Laravel's mailer.
 * Without a custom server, mail goes through the local Postfix (which relays).
 */
class MailConfigurator
{
    public const ENCRYPTIONS = ['starttls', 'tls', 'none'];

    public function __construct(private readonly Settings $settings) {}

    public function settings(): array
    {
        $s = $this->settings;

        return [
            'enabled' => (bool) $s->get('mail.enabled', false),
            'host' => $s->get('mail.host', ''),
            'port' => (int) $s->get('mail.port', 587),
            'encryption' => $s->get('mail.encryption', 'starttls'),
            'username' => $s->get('mail.username', ''),
            'password' => $s->get('mail.password', ''),
            'from_address' => $s->get('mail.from_address', config('mail.from.address')),
            'from_name' => $s->get('mail.from_name', config('mail.from.name')),
            'ehlo_domain' => $s->get('mail.ehlo_domain', ''),
            'verify_peer' => (bool) $s->get('mail.verify_peer', true),
            'timeout' => (int) $s->get('mail.timeout', 15),
        ];
    }

    /** Build a mailer config array from settings (also used to test unsaved settings). */
    public function mailerConfig(array $s): array
    {
        if (! $s['enabled']) {
            return [
                'transport' => 'smtp', 'scheme' => 'smtp', 'host' => '127.0.0.1', 'port' => 25,
                'auto_tls' => false, 'timeout' => 10,
            ];
        }

        return array_filter([
            'transport' => 'smtp',
            'scheme' => $s['encryption'] === 'tls' ? 'smtps' : 'smtp',
            'host' => $s['host'],
            'port' => $s['port'],
            'username' => $s['username'] ?: null,
            'password' => $s['password'] ?: null,
            'timeout' => $s['timeout'] ?: 15,
            'local_domain' => $s['ehlo_domain'] ?: null,
            // STARTTLS is required in starttls mode, so credentials never go out in plain text.
            'require_tls' => $s['encryption'] === 'starttls' ? true : null,
            'auto_tls' => $s['encryption'] === 'none' ? false : null,
            'verify_peer' => $s['verify_peer'] ? null : false,
        ], fn ($v) => $v !== null && $v !== '');
    }

    public function apply(?array $settings = null, string $mailer = 'smtp'): void
    {
        $s = $settings ?? $this->settings();
        Config::set("mail.mailers.{$mailer}", $this->mailerConfig($s));
        Config::set('mail.default', $mailer);
        if (! empty($s['from_address'])) {
            Config::set('mail.from', ['address' => $s['from_address'], 'name' => $s['from_name'] ?: config('app.name')]);
        }
    }
}
