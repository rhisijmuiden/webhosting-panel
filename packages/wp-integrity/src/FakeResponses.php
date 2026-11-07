<?php

declare(strict_types=1);

namespace Rhis\WpIntegrity;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Str;

/** wpint.* answers for the fake agent (WHP_AGENT_DRIVER=fake, local development and tests). */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('wpint.check', fn (array $p) => ($p['background'] ?? false) ? ['job' => (string) Str::uuid()] : self::report($p));
        $agent->respond('wpint.report', fn (array $p) => self::report($p));
        $agent->respond('wpint.restore', fn () => ['job' => (string) Str::uuid()]);
    }

    /** A report with one changed core file, a backdoor and a commercial plugin. */
    public static function report(array $p): array
    {
        $now = time();

        return [
            'domain' => $p['domain'] ?? '', 'path' => $p['path'] ?? '', 'version' => '6.6.2', 'locale' => 'nl_NL', 'root_rel' => 'public',
            'status' => 'changed', 'changed_files' => 3,
            'components' => [
                ['type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'version' => '6.6.2', 'locale' => 'nl_NL', 'status' => 'changed',
                    'message' => null, 'base' => '', 'total_files' => 2, 'files' => [
                        ['file' => 'wp-includes/functions.php', 'issue' => 'modified', 'md5' => md5('a'), 'size' => 281234, 'mtime' => $now - 86400 * 2],
                        ['file' => 'wp-admin/css/colors/blue/admin.php', 'issue' => 'added', 'md5' => md5('b'), 'size' => 4120, 'mtime' => $now - 86400 * 2, 'malware' => 'PHP.Webshell.Generic'],
                    ]],
                ['type' => 'plugin', 'slug' => 'contact-form-7', 'name' => 'Contact Form 7', 'version' => '5.9.8', 'active' => true, 'status' => 'changed',
                    'message' => null, 'base' => 'wp-content/plugins/contact-form-7', 'total_files' => 1, 'files' => [
                        ['file' => 'includes/mail.php', 'issue' => 'modified', 'md5' => md5('c'), 'size' => 12011, 'mtime' => $now - 3600 * 5],
                    ]],
                ['type' => 'plugin', 'slug' => 'akismet', 'name' => 'Akismet Anti-spam', 'version' => '5.3.3', 'active' => true, 'status' => 'ok',
                    'message' => null, 'base' => 'wp-content/plugins/akismet', 'total_files' => 0, 'files' => []],
                ['type' => 'plugin', 'slug' => 'premium-builder', 'name' => 'Premium Builder', 'version' => '3.1.0', 'active' => false, 'status' => 'unverifiable',
                    'message' => 'Not in the WordPress.org plugin directory (commercial or custom plugin)', 'base' => 'wp-content/plugins/premium-builder', 'total_files' => 0, 'files' => []],
            ],
            'malware_elsewhere' => [['file' => 'wp-content/uploads/2026/10/cache.php', 'signature' => 'Php.Malware.Agent']],
            'malware_scanned_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 3600 * 6),
            'errors' => [],
            'checked_at' => $now,
        ];
    }
}
