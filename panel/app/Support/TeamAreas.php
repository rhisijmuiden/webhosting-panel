<?php

namespace App\Support;

/**
 * The areas an owner can give a team member, and which client routes belong
 * to each area. A team member can only open client routes of an area they
 * have; a client route that is in no area stays closed to team members.
 *
 * A package with new client pages adds its routes to an area:
 *
 *   TeamAreas::extend('mail', ['client.webmail.*']);
 */
class TeamAreas
{
    /** Pages every team member can open: the overview and the list of their websites. */
    public const ALWAYS = ['client.dashboard', 'client.websites.index', 'client.websites.show'];

    private static array $areas = [
        'websites' => [
            'label' => 'Website settings',
            'help' => 'PHP version and settings, page cache, domain aliases, subdomains and copies.',
            'icon' => 'globe',
            'routes' => ['client.websites.update', 'client.websites.php', 'client.domain-aliases.*', 'client.subdomains.*', 'client.clones.*'],
        ],
        'files' => [
            'label' => 'Files',
            'help' => 'File manager, code editor and terminal.',
            'icon' => 'code',
            'routes' => ['client.files.*'],
        ],
        'databases' => [
            'label' => 'Databases',
            'help' => 'Databases and database users of the website.',
            'icon' => 'database',
            'routes' => ['client.databases.*'],
        ],
        'wordpress' => [
            'label' => 'WordPress & apps',
            'help' => 'WordPress toolkit and one-click apps.',
            'icon' => 'wordpress',
            'routes' => ['client.wordpress.*', 'client.apps.*'],
        ],
        'git' => [
            'label' => 'Git deployments',
            'help' => 'Repositories, deployments and rollbacks.',
            'icon' => 'refresh',
            'routes' => ['client.git.*'],
        ],
        'mail' => [
            'label' => 'Email',
            'help' => 'Mailboxes, forwarders, spam filter and quarantine.',
            'icon' => 'mail',
            'routes' => ['client.mail.*'],
        ],
        'dns' => [
            'label' => 'DNS',
            'help' => 'DNS records, DKIM and connecting the domain.',
            'icon' => 'globe',
            'routes' => ['client.mail.domains.dns.*', 'client.mail.domains.connect', 'client.mail.domains.connect.*', 'client.mail.domains.dkim'],
        ],
        'backups' => [
            'label' => 'Backups',
            'help' => 'Make and restore backups, backup locations.',
            'icon' => 'backup',
            'routes' => ['client.backups.*'],
        ],
        'security' => [
            'label' => 'Security',
            'help' => 'Login protection and security alerts.',
            'icon' => 'shield',
            'routes' => ['client.protection.*', 'client.vulns.*'],
        ],
        'billing' => [
            'label' => 'Orders',
            'help' => 'Order and cancel services.',
            'icon' => 'package',
            'routes' => ['client.shop.*'],
        ],
    ];

    /** @return array<string, array{label: string, help: string, icon: string, routes: list<string>}> */
    public static function all(): array
    {
        return self::$areas;
    }

    public static function keys(): array
    {
        return array_keys(self::$areas);
    }

    public static function extend(string $area, array $routes): void
    {
        if (isset(self::$areas[$area])) {
            self::$areas[$area]['routes'] = array_values(array_unique([...self::$areas[$area]['routes'], ...$routes]));
        }
    }

    /**
     * The area a client route belongs to. The most specific pattern wins, so
     * client.mail.domains.dns.check is DNS and client.mail.domains.show is Email.
     */
    public static function areaFor(?string $route): ?string
    {
        if ($route === null) {
            return null;
        }
        $best = null;
        $bestLength = -1;
        foreach (self::$areas as $key => $area) {
            foreach ($area['routes'] as $pattern) {
                if (\Illuminate\Support\Str::is($pattern, $route) && strlen($pattern) > $bestLength) {
                    [$best, $bestLength] = [$key, strlen($pattern)];
                }
            }
        }

        return $best;
    }
}
