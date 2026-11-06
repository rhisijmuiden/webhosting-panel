<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities;

use App\Services\Settings;

/** Settings of the security alerts: database settings first, config/env as default. */
final class Options
{
    public function __construct(private readonly Settings $settings) {}

    public function source(): string
    {
        $source = (string) $this->settings->get('vuln.source', config('vulnerabilities.source'));
        if ($source === 'wordfence' && ! $this->wordfenceKey()) {
            return 'wpvulnerability';
        }

        return in_array($source, ['wordfence', 'wpvulnerability'], true) ? $source : 'wpvulnerability';
    }

    public function configuredSource(): string
    {
        return (string) $this->settings->get('vuln.source', config('vulnerabilities.source'));
    }

    public function wordfenceKey(): ?string
    {
        return $this->settings->get('vuln.wordfence_key') ?: null;
    }

    public function mailEnabled(): bool
    {
        return (bool) $this->settings->get('vuln.mail_enabled', config('vulnerabilities.mail_enabled'));
    }

    public function minSeverity(): string
    {
        $s = (string) $this->settings->get('vuln.min_severity', config('vulnerabilities.min_severity'));

        return in_array($s, ['critical', 'high', 'medium', 'low'], true) ? $s : 'medium';
    }

    public function reminderDays(): int
    {
        return max(0, (int) $this->settings->get('vuln.reminder_days', config('vulnerabilities.reminder_days')));
    }

    public function maxReminders(): int
    {
        return max(0, (int) $this->settings->get('vuln.max_reminders', config('vulnerabilities.max_reminders')));
    }

    public function bcc(): ?string
    {
        $bcc = $this->settings->get('vuln.bcc');

        return $bcc && filter_var($bcc, FILTER_VALIDATE_EMAIL) ? $bcc : null;
    }

    public function supportEmail(): ?string
    {
        return $this->settings->get('vuln.support_email') ?: config('vulnerabilities.support_email') ?: config('mail.from.address');
    }

    public function save(array $data): void
    {
        foreach (['source', 'mail_enabled', 'min_severity', 'reminder_days', 'max_reminders', 'bcc', 'support_email'] as $k) {
            if (array_key_exists($k, $data)) {
                $v = $data[$k];
                $this->settings->set("vuln.$k", is_bool($v) ? ($v ? '1' : '0') : $v);
            }
        }
        if (! empty($data['wordfence_key'])) {
            $this->settings->set('vuln.wordfence_key', $data['wordfence_key'], encrypted: true);
        }
        if (! empty($data['forget_key'])) {
            $this->settings->forget('vuln.wordfence_key');
        }
    }
}
