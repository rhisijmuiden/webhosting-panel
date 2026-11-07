<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * The "Tools & settings" overview for admins: every server and panel setting,
 * grouped by subject. Only pages that exist on this install are shown, so a
 * package that is not installed simply leaves its links out.
 *
 * Packages can add a link to a group without touching this file:
 *
 *   ToolsCatalog::add('mail', 'Spam filter', 'admin.spam.index', 'Rspamd scores and greylisting');
 */
class ToolsCatalog
{
    /** key => [label, icon, intro]. Order is the order on the page. */
    public const GROUPS = [
        'security' => ['Security', 'shield', 'Protect the server and the panel.'],
        'mail' => ['Mail', 'mail', 'Outgoing mail, mail domains and delivery.'],
        'web' => ['Web & PHP', 'code', 'How websites run on this server.'],
        'server' => ['Server management', 'server', 'Services, usage and backups.'],
        'tools' => ['Tools & migration', 'migrate', 'Move sites in and work with files.'],
        'panel' => ['Panel', 'settings', 'Updates, integrations and logs of the panel itself.'],
        'account' => ['Your account', 'user', 'Your own login and preferences.'],
    ];

    private static array $extra = [];

    public static function add(string $group, string $label, string $route, ?string $description = null, array $parameters = []): void
    {
        self::$extra[$group][] = compact('label', 'route', 'description', 'parameters');
    }

    public static function reset(): void
    {
        self::$extra = [];
    }

    /** Group key => list of [label, route, description, parameters]. */
    private static function defaults(): array
    {
        return [
            'security' => [
                ['Security center', 'admin.vulns.index', 'Vulnerabilities and security score of all sites'],
                ['Security alert settings', 'admin.vulns.settings', 'Who gets told about findings, and how'],
                ['Attack blocking', 'admin.security.index', 'CrowdSec decisions, bans and unbans'],
                ['Login protection', 'admin.protection.edit', 'Brute-force protection and trusted IP addresses'],
                ['Firewall', 'admin.firewall.index', 'Open ports and blocked networks (nftables)'],
                ['Two-factor authentication', 'profile.security', 'Protect your admin login with an app code'],
            ],
            'mail' => [
                ['Mail server (SMTP)', 'admin.settings.mail.edit', 'The SMTP server the panel sends its own mail with'],
                ['DNS & mail server', 'admin.mail.settings.edit', 'Cloudflare, relay host, DKIM, MTA-STS and DANE'],
                ['Mail domains', 'admin.mail.domains.index', 'Mailboxes, DNS records and checks per domain'],
                ['Mail log', 'admin.mail.log.index', 'Find out what happened to a message'],
            ],
            'web' => [
                ['PHP settings', 'admin.php.index', 'PHP versions, defaults and extensions'],
                ['Domain aliases', 'admin.domain-aliases.index', 'Extra domains that point to a website'],
                ['WordPress', 'admin.wordpress.index', 'All WordPress sites, updates and security'],
                ['App installer', 'admin.apps.index', 'One-click apps for customers'],
                ['Git deployments', 'admin.git.index', 'Deploy websites from a Git repository'],
            ],
            'server' => [
                ['Services', 'admin.services.index', 'Status of Nginx, PHP, MariaDB, mail and more'],
                ['Statistics', 'admin.statistics.index', 'CPU, memory, disk and traffic'],
                ['Backups', 'admin.backups.index', 'Full and incremental backups, schedule and locations'],
                ['Backup browser', 'admin.backups.browse', 'Restore single files, databases or mailboxes'],
                ['Customer backups', 'admin.backups.customers', 'Backups customers made themselves'],
            ],
            'tools' => [
                ['Migration', 'admin.migration.index', 'Move sites from Plesk and other servers'],
                ['Migration status', 'admin.migration.status', 'Progress of running migrations'],
                ['File manager', 'admin.files.index', 'Browse and edit the files of any website'],
            ],
            'panel' => [
                ['Updates & release channel', 'admin.system.index', 'Version, updates and the production/test switch'],
                ['CRM API', 'admin.crm-api.index', 'Tokens for your CRM and billing'],
                ['Activity log', 'admin.activity.index', 'Who changed what, and when'],
            ],
            'account' => [
                ['Profile', 'profile.edit', 'Name, email address, password and language'],
            ],
        ];
    }

    /**
     * The groups that have at least one link, each with its links.
     *
     * @return array<string, array{label: string, icon: string, intro: string, items: array}>
     */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => [$label, $icon, $intro]) {
            $items = collect(self::defaults()[$key] ?? [])
                ->map(fn ($i) => ['label' => $i[0], 'route' => $i[1], 'description' => $i[2] ?? null, 'parameters' => []])
                ->concat(self::$extra[$key] ?? [])
                ->filter(fn ($i) => Route::has($i['route']))
                ->unique('route')
                ->values()
                ->all();
            if ($items !== []) {
                $groups[$key] = compact('label', 'icon', 'intro', 'items');
            }
        }

        return $groups;
    }
}
