<?php

declare(strict_types=1);

namespace Rhis\Security;

/** Readable names for CrowdSec scenarios shown to customers. */
final class Scenarios
{
    public static function label(string $scenario): string
    {
        $base = preg_replace('/-perm$/', '', $scenario);
        $perm = $base !== $scenario;
        $label = match (true) {
            $base === 'whp/wordpress-bf' => __('WordPress password guessing'),
            $base === 'whp/mail-bf' => __('Mailbox password guessing'),
            $base === 'whp/ssh-bf' => __('SFTP/SSH password guessing'),
            $base === 'whp/panel-bf' => __('Control panel password guessing'),
            str_contains($scenario, 'wordpress') => __('WordPress probing'),
            str_contains($scenario, 'cve') => __('Exploit attempt'),
            str_contains($scenario, 'scan') || str_contains($scenario, 'probing') || str_contains($scenario, 'crawl') => __('Scanning for weaknesses'),
            default => __('Suspicious traffic'),
        };

        return $perm ? $label.' ('.__('permanent').')' : $label;
    }
}
