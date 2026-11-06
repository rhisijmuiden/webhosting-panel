<?php

declare(strict_types=1);

namespace Rhis\Security;

use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;

/**
 * Writes failed panel logins (password and two-factor) to the auth log in
 * syslog format, where CrowdSec counts them for the "panel" service.
 */
final class FailedLoginLog
{
    public function __construct(private readonly Request $request) {}

    public function handle(Failed $event): void
    {
        $user = $event->credentials['email'] ?? $event->user?->email ?? '-';
        self::write((string) $this->request->ip(), (string) $user);
    }

    public static function line(string $ip, string $user, ?\DateTimeInterface $at = null): string
    {
        // Only characters that cannot break the log format or the parser.
        $user = preg_replace('/[^A-Za-z0-9.@_+\-]/', '_', mb_substr($user, 0, 120)) ?: '-';
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) gethostname()) ?: 'localhost';
        $at ??= new \DateTimeImmutable;

        return sprintf("%s %s whp-panel[%d]: auth_failed ip=%s user=%s\n", $at->format(DATE_RFC3339), $host, getmypid(), $ip, $user);
    }

    public static function write(string $ip, string $user): void
    {
        $path = (string) config('security.auth_log');
        if ($path === '' || ! is_dir(dirname($path))) {
            return;
        }
        // Never break a login because the log is not writable.
        @file_put_contents($path, self::line($ip, $user), FILE_APPEND | LOCK_EX);
    }
}
