<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Services\Settings;

/** Settings of the mail log, kept on the mail log page itself. */
final class MailLogSettings
{
    public const RETENTION = 'dnsmail.maillog_retention_days';

    public const ALERT_PER_HOUR = 'dnsmail.maillog_alert_per_hour';

    public const ALERT_BOUNCE_PERCENT = 'dnsmail.maillog_alert_bounce_percent';

    public function __construct(private readonly Settings $settings)
    {
    }

    public function retentionDays(): int
    {
        return $this->int(self::RETENTION, 30, 1, 365);
    }

    /** Outgoing messages per hour from one customer above which the log flags them. */
    public function alertPerHour(): int
    {
        return $this->int(self::ALERT_PER_HOUR, 200, 1, 100000);
    }

    /** Share of bounced/refused outgoing mail (with at least 20 messages) that flags a customer. */
    public function alertBouncePercent(): int
    {
        return $this->int(self::ALERT_BOUNCE_PERCENT, 20, 1, 100);
    }

    public function save(int $retention, int $perHour, int $bouncePercent): void
    {
        $this->settings->set(self::RETENTION, $retention);
        $this->settings->set(self::ALERT_PER_HOUR, $perHour);
        $this->settings->set(self::ALERT_BOUNCE_PERCENT, $bouncePercent);
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $v = (int) $this->settings->get($key, $default);

        return $v >= $min && $v <= $max ? $v : $default;
    }
}
