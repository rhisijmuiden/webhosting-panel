<?php

declare(strict_types=1);

namespace Rhis\Monitoring;

use Rhis\Monitoring\Models\Check;
use Rhis\Monitoring\Models\Incident;

/** Sentences for checks and incidents, in the reader's language (never stored). */
final class Describe
{
    public static function headline(string $kind, string $severity, string $label): string
    {
        $crit = $severity === 'critical';

        return match ($kind) {
            'website' => __('Website :label does not answer', ['label' => $label]),
            'ssl' => $crit ? __('Certificate of :label is expired or not valid', ['label' => $label])
                : __('Certificate of :label expires soon', ['label' => $label]),
            'disk' => $crit ? __('Disk :label is full', ['label' => $label]) : __('Disk :label is almost full', ['label' => $label]),
            'inode' => __('Disk :label runs out of files (inodes)', ['label' => $label]),
            'service' => __('Service :label is not running', ['label' => $label]),
            'memory' => __('Memory is almost full'),
            'load' => __('The server is very busy'),
            'mail_queue' => __('Many mails are waiting to be sent'),
            'agent' => __('The server agent does not answer'),
            default => $label,
        };
    }

    public static function incident(Incident $i): string
    {
        return self::headline($i->kind, $i->severity, $i->title);
    }

    public static function check(Check $c): string
    {
        return self::headline($c->kind, $c->status === 'ok' ? 'warning' : $c->status, $c->label);
    }

    public static function kind(string $kind): string
    {
        return match ($kind) {
            'website' => __('Website'),
            'ssl' => __('Certificate'),
            'disk', 'inode' => __('Disk'),
            'service' => __('Service'),
            'memory' => __('Memory'),
            'load' => __('Load'),
            'mail_queue' => __('Mail queue'),
            'agent' => __('Server agent'),
            default => ucfirst($kind),
        };
    }

    /** What to do about it, for the alert messages. */
    public static function advice(string $kind): ?string
    {
        return match ($kind) {
            'website' => __('Open the website in the panel and check the error log, PHP version and recent changes.'),
            'ssl' => __('Let\'s Encrypt renews certificates 30 days before they expire, so renewing failed. Check that the domain still points to this server and renew it in the panel.'),
            'disk', 'inode' => __('Remove old backups, logs or caches, or make the disk bigger.'),
            'service' => __('Restart the service under Services, and look at its log when it stops again.'),
            'memory' => __('Look at Statistics to see which website uses the most memory.'),
            'load' => __('Look at Statistics to see which website causes the load.'),
            'mail_queue' => __('Look at the mail log: a website may send spam, or the relay refuses mail.'),
            'agent' => __('Check whp-agent.service on the server (systemctl status whp-agent).'),
            default => null,
        };
    }
}
