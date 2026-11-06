<?php

declare(strict_types=1);

namespace Rhis\Vulnerabilities\Testing;

use App\Services\Agent\FakeAgentClient;
use Illuminate\Support\Str;

/** Answers of the fake agent (local development and tests) for the actions this package uses. */
final class FakeAgentResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $agent->respond('vuln.match', fn (array $p) => self::match($p));
        $agent->respond('vuln.status', ['source' => 'wordfence', 'ready' => true, 'records' => 42504, 'updated_at' => now()->subHours(5)->timestamp]);
        $agent->respond('vuln.sync', fn () => ['job' => (string) Str::uuid()]);
        $agent->respond('malware.report', fn (array $p) => ['available' => true, 'domain' => $p['domain'] ?? '', 'scanned_at' => null, 'infected' => 0, 'findings' => []]);
        $agent->respond('malware.scan', fn () => ['job' => (string) Str::uuid()]);
        $agent->respond('posture.get', fn () => [
            'updates' => ['total' => 3, 'security' => 0, 'unattended' => true], 'reboot_required' => false,
            'ssh' => ['root_login' => 'prohibit-password', 'password_auth' => 'no', 'port' => '22'],
            'services' => ['crowdsec' => true, 'crowdsec-firewall-bouncer' => true, 'nftables' => true, 'clamav-daemon' => true, 'clamav-freshclam' => true],
            'clamav_signature_age' => 7200, 'malware_scanner' => true, 'last_malware_scan' => now()->subHours(6)->timestamp,
            'vulndb' => ['source' => 'wordfence', 'ready' => true, 'records' => 42504, 'updated_at' => now()->subHours(5)->timestamp],
            'checked_at' => time(),
        ]);
    }

    /** Contact Form 7 below 5.9.9 and Astra below 4.8.0 are "vulnerable". */
    private static function match(array $p): array
    {
        $db = [
            'plugin:contact-form-7' => ['to' => '5.9.9', 'v' => [
                'id' => 'f1d3c2a0-0000-4000-8000-cf7000000001', 'title' => 'Contact Form 7 <= 5.9.8 - Reflected Cross-Site Scripting',
                'description' => 'The plugin does not escape a parameter before printing it back.', 'cve' => 'CVE-2024-6625', 'cvss_score' => 7.2, 'severity' => 'high',
                'fixed_in' => '5.9.9', 'remediation' => 'Update to version 5.9.9, or a newer patched version',
                'references' => ['https://www.wordfence.com/threat-intel/vulnerabilities/id/f1d3c2a0'], 'copyright' => 'Copyright 2012-2026 Defiant Inc.',
            ]],
            'theme:astra' => ['to' => '4.8.0', 'v' => [
                'id' => 'a57a0000-0000-4000-8000-a57a00000002', 'title' => 'Astra <= 4.7.x - Authenticated (Contributor+) Stored XSS',
                'description' => null, 'cve' => 'CVE-2024-7777', 'cvss_score' => 6.4, 'severity' => 'medium', 'fixed_in' => '4.8.0',
                'remediation' => null, 'references' => [], 'copyright' => null,
            ]],
        ];
        $matches = [];
        foreach ($p['items'] ?? [] as $item) {
            $hit = $db[$item['type'].':'.$item['slug']] ?? null;
            if ($hit && version_compare($item['version'], $hit['to'], '<')) {
                $matches[] = $item + ['vulnerabilities' => [$hit['v']]];
            }
        }

        return ['source' => $p['source'] ?? 'wpvulnerability', 'matches' => $matches, 'errors' => [], 'checked' => count($p['items'] ?? [])];
    }
}
