<?php

declare(strict_types=1);

namespace Rhis\Migration\Services;

use App\Models\Website;

/**
 * How a domain from a source inventory becomes a website here. Pure, so the
 * same rules apply to the Plesk migration and later to the customer
 * migration assistant and the WordPress helper.
 */
final class SitePlan
{
    /**
     * @param  array<string, mixed>  $domain  one entry of inventory.domains
     * @return array{profile: string, php: string, php_note: ?array, docroot: string, target: string, aliases: list<string>}
     */
    public static function for(array $domain, string $minPhp = '8.3'): array
    {
        $web = $domain['web'] ?? [];
        $sub = trim((string) ($web['sub'] ?? ''), '/');
        $app = (string) ($web['app'] ?? 'php');
        $profile = in_array($app, Website::PROFILES, true) ? $app : 'php';

        // The copied base dir becomes public/ (plain docroot) or app/ (docroot deeper inside).
        if ($sub === '') {
            $target = 'public';
            $docroot = 'public';
        } else {
            $target = 'app';
            $docroot = 'app/'.$sub;
        }
        if ($profile === 'laravel' && $docroot !== 'app/public') {
            $profile = 'php';
        }

        [$php, $note] = self::php((string) ($domain['php'] ?? ''), $minPhp);

        $name = strtolower((string) $domain['name']);
        $aliases = [];
        if (! str_starts_with($name, 'www.')) {
            $aliases[] = 'www.'.$name;
        }
        foreach ($domain['aliases'] ?? [] as $a) {
            if (($a['web'] ?? true) && preg_match('/^(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/', (string) $a['name'])) {
                $aliases[] = $a['name'];
                $aliases[] = 'www.'.$a['name'];
            }
        }

        return [
            'profile' => $profile, 'php' => $php, 'php_note' => $note, 'docroot' => $docroot, 'target' => $target,
            'aliases' => array_values(array_unique($aliases)),
        ];
    }

    /** @return array{0: string, 1: ?array{text: string, params: array<string, string>}} */
    public static function php(string $source, string $min): array
    {
        $versions = Website::PHP_VERSIONS;
        if ($source === '') {
            return [in_array('8.4', $versions, true) ? '8.4' : end($versions), null];
        }
        if (in_array($source, $versions, true)) {
            return [$source, null];
        }
        if (version_compare($source, $min, '<')) {
            return [$min, ['text' => 'PHP :from on the old server, now :to. Test the website before switching DNS.', 'params' => ['from' => $source, 'to' => $min]]];
        }

        return [end($versions), null];
    }

    /** Hash for Dovecot from what the source gave: plain text or a crypt hash. */
    public static function mailHash(string $scheme, string $secret): ?string
    {
        if ($scheme === 'plain' && $secret !== '') {
            return '{ARGON2ID}'.password_hash($secret, PASSWORD_ARGON2ID);
        }
        if ($scheme === 'crypt') {
            return match (true) {
                str_starts_with($secret, '$6$') => '{SHA512-CRYPT}'.$secret,
                str_starts_with($secret, '$5$') => '{SHA256-CRYPT}'.$secret,
                str_starts_with($secret, '$1$') => '{MD5-CRYPT}'.$secret,
                default => null,
            };
        }

        return null;
    }
}
