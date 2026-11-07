<?php

declare(strict_types=1);

namespace Rhis\Migration\Wizard;

use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\Migration\Models\MigrationItem;
use Throwable;

/**
 * The steps of the per-domain migration wizard and what was checked in each.
 * A check is [level, text] with level ok, warn or bad; a bad check blocks the
 * final switch, a warning only asks for attention.
 */
final class WizardSteps
{
    public const KEYS = ['website', 'databases', 'mail', 'ssl', 'dns', 'final'];

    /** Hours after the last copy when the wizard suggests syncing again before the switch. */
    public const FRESH_HOURS = 24;

    /** @return array<string, string> */
    public static function titles(): array
    {
        return [
            'website' => __('Website'), 'databases' => __('Databases'), 'mail' => __('Mailboxes'),
            'ssl' => __('SSL certificate'), 'dns' => __('DNS records'), 'final' => __('Final check'),
        ];
    }

    /**
     * @return array<string, array{key: string, title: string, applicable: bool, confirmed: bool, checks: list<array{0: string, 1: string}>, state: string}>
     */
    public static function all(MigrationItem $item, ?MailDomain $domain): array
    {
        $titles = self::titles();
        $steps = [];
        foreach (self::KEYS as $key) {
            if ($key === 'final') {
                continue;
            }
            [$applicable, $checks] = match ($key) {
                'website' => self::website($item),
                'databases' => self::databases($item),
                'mail' => self::mail($item, $domain),
                'ssl' => self::ssl($item),
                'dns' => self::dns($item, $domain),
            };
            $confirmed = ! $applicable || $item->wizardValue("confirmed.$key") !== null;
            $steps[$key] = ['key' => $key, 'title' => $titles[$key], 'applicable' => $applicable, 'confirmed' => $confirmed,
                'checks' => $checks, 'state' => self::state($checks, $confirmed, $applicable)];
        }
        $blocking = self::blocking($item, $steps);
        $switched = $item->wizardValue('switched_at') !== null || in_array($item->status, ['live', 'done'], true);
        $steps['final'] = ['key' => 'final', 'title' => $titles['final'], 'applicable' => true, 'confirmed' => $switched,
            'checks' => match (true) {
                $switched => [['ok', __('DNS switched to this server')]],
                $blocking === [] => [['ok', __('Everything is ready for the switch.')]],
                default => array_map(fn ($t) => ['bad', $t], $blocking),
            },
            'state' => $switched ? 'ok' : ($blocking === [] ? 'todo' : 'blocked')];

        return $steps;
    }

    /**
     * Reasons the definitive switch cannot start yet.
     *
     * @param  array<string, array<string, mixed>>  $steps
     * @return list<string>
     */
    public static function blocking(MigrationItem $item, array $steps): array
    {
        $out = [];
        if ($item->status !== 'copied') {
            $out[] = match ($item->status) {
                'live', 'done' => __('DNS was already switched.'),
                'failed' => __('The last copy stopped with an error. Try again first.'),
                default => __('The copy is not finished yet.'),
            };
        }
        foreach ($steps as $key => $s) {
            if ($key === 'final') {
                continue;
            }
            if (! $s['confirmed']) {
                $out[] = __('Step “:step” is not confirmed yet.', ['step' => $s['title']]);
            }
            foreach ($s['checks'] as [$level, $text]) {
                if ($level === 'bad') {
                    $out[] = $s['title'].': '.$text;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @param list<array{0: string, 1: string}> $checks */
    private static function state(array $checks, bool $confirmed, bool $applicable): string
    {
        if (! $applicable) {
            return 'skip';
        }
        $levels = array_column($checks, 0);

        return match (true) {
            in_array('bad', $levels, true) => 'bad',
            $confirmed => 'ok',
            in_array('warn', $levels, true) => 'warn',
            default => 'todo',
        };
    }

    /** @return array{0: bool, 1: list<array{0: string, 1: string}>} */
    private static function website(MigrationItem $item): array
    {
        $o = $item->options ?? [];
        $d = $item->source_data ?? [];
        if (! ($o['files'] ?? false) || empty($d['web'])) {
            return [false, [['ok', __('No website files are migrated for this domain.')]]];
        }
        $checks = [];
        $checks[] = $item->copied_at !== null
            ? ['ok', __('Website files copied (:size).', ['size' => $item->result['files']['stats']['total_size'] ?? '–'])]
            : ['bad', __('The website files are not copied yet.')];
        $last = $item->synced_at ?? $item->copied_at;
        if ($last !== null && $last->lt(now()->subHours(self::FRESH_HOURS))) {
            $checks[] = ['warn', __('The last copy is from :time. Sync again right before the switch.', ['time' => $last->diffForHumans()])];
        } elseif ($last !== null) {
            $checks[] = ['ok', __('Last copy :time.', ['time' => $last->diffForHumans()])];
        }
        $http = $item->wizardValue('http');
        $checks[] = match (true) {
            $http === null => ['warn', __('Not tested on this server yet. Use “Test the website”.')],
            ($http['error'] ?? null) !== null => ['bad', __('The website does not answer on this server: :error', ['error' => $http['error']])],
            $http['status'] >= 500 => ['bad', __('The website answers with HTTP :status on this server.', ['status' => $http['status']])],
            $http['status'] >= 400 => ['warn', __('The website answers with HTTP :status on this server.', ['status' => $http['status']])],
            default => ['ok', __('The website answers with HTTP :status on this server.', ['status' => $http['status']])],
        };
        if (($d['web']['app'] ?? '') === 'wordpress') {
            $wp = $item->result['wordpress'] ?? [];
            $checks[] = $wp !== [] && collect($wp)->every(fn ($w) => $w['ok'] ?? false)
                ? ['ok', __('WordPress is configured for this server.')]
                : ['warn', __('WordPress settings are not fixed for this server yet. Use “Fix WordPress settings”.')];
        }

        return [true, $checks];
    }

    /** @return array{0: bool, 1: list<array{0: string, 1: string}>} */
    private static function databases(MigrationItem $item): array
    {
        $dbs = ($item->options['databases'] ?? false) ? ($item->source_data['databases'] ?? []) : [];
        if ($dbs === []) {
            return [false, [['ok', __('No databases are migrated for this domain.')]]];
        }
        $checks = [];
        foreach ($dbs as $db) {
            $res = collect($item->result['databases'] ?? [])->firstWhere('name', $db['name']);
            $checks[] = match (true) {
                $res === null => ['bad', __('Database :name is not copied yet.', ['name' => $db['name']])],
                (bool) ($res['ok'] ?? false) => ['ok', __('Database :name copied.', ['name' => $db['name']])],
                default => ['bad', __('Copying database :name failed.', ['name' => $db['name']])],
            };
        }

        return [true, $checks];
    }

    /** @return array{0: bool, 1: list<array{0: string, 1: string}>} */
    private static function mail(MigrationItem $item, ?MailDomain $domain): array
    {
        $checks = [];
        $external = self::externalMail($item);
        $provider = $domain?->provider ?? 'rhis';
        if ($external !== null && $provider === 'rhis') {
            $checks[] = ['warn', __('Mail of this domain runs at :service now. Choose below whether it moves to this server or stays there.', ['service' => $external])];
        }
        if ($provider !== 'rhis') {
            $checks[] = ['ok', __('Mail stays at the current provider; the switch leaves MX, SPF and autodiscover alone.')];

            return [true, $checks];
        }
        $boxes = ($item->options['mail'] ?? false) ? ($item->source_data['mailboxes'] ?? []) : [];
        if ($boxes === []) {
            $checks[] = ['ok', __('No mailboxes to copy. Mail for the domain will arrive on this server.')];
        }
        foreach ($boxes as $box) {
            $addr = $box['local'].'@'.$item->domain;
            $res = collect($item->result['mail'] ?? [])->firstWhere('local', $box['local']);
            $checks[] = match (true) {
                $res === null => ['bad', __('Mailbox :box is not copied yet.', ['box' => $addr])],
                (bool) ($res['ok'] ?? false) => ['ok', __('Mailbox :box copied.', ['box' => $addr])],
                default => ['bad', __('Copying mailbox :box failed.', ['box' => $addr])],
            };
        }

        return [true, $checks];
    }

    /** @return array{0: bool, 1: list<array{0: string, 1: string}>} */
    private static function ssl(MigrationItem $item): array
    {
        if ($item->website === null) {
            return [false, [['ok', __('No website, so no certificate.')]]];
        }
        $checks = [];
        if ($item->result['ssl']['installed'] ?? false) {
            $checks[] = ['ok', __('The certificate of the old server is installed, so HTTPS works right after the switch.')];
        } elseif (($item->options['ssl'] ?? false) && ! empty($item->source_data['ssl'])) {
            $checks[] = ['warn', __('The certificate of the old server was not installed. HTTPS may show a warning for a few minutes after the switch.')];
        } else {
            $checks[] = ['warn', __('No certificate yet. HTTPS may show a warning for a few minutes after the switch.')];
        }
        $checks[] = ['ok', __('Let\'s Encrypt is requested right after the switch and renews automatically.')];

        return [true, $checks];
    }

    /** @return array{0: bool, 1: list<array{0: string, 1: string}>} */
    private static function dns(MigrationItem $item, ?MailDomain $domain): array
    {
        if ($domain === null) {
            return [true, [['bad', __('The mail domain for :domain is missing. Prepare the migration again.', ['domain' => $item->domain])]]];
        }
        $sel = $item->wizardValue('dns');
        $checks = [];
        if (! $domain->cloudflare_zone_id && $domain->dns_status === 'no_zone') {
            $checks[] = ['warn', __('The domain is not in Cloudflare yet. Add it, or switch by hand with a zone file.')];
        }
        if ($sel !== null) {
            $checks[] = ['ok', __(':changed changes chosen, :kept kept as they are.', ['changed' => count($sel['keys'] ?? []), 'kept' => max(0, ($sel['total'] ?? 0) - count($sel['keys'] ?? []))])];
        } else {
            $checks[] = ['warn', __('Choose which records to change.')];
        }

        return [true, $checks];
    }

    /** The mail provider the old DNS points at (from Plesk's records), if not a mail server of its own. */
    public static function externalMail(MigrationItem $item): ?string
    {
        $records = [];
        foreach ($item->source_data['dns'] ?? [] as $r) {
            try {
                $records[] = new DnsRecord((string) $r['type'], (string) $r['name'], (string) $r['content'], priority: isset($r['priority']) ? (int) $r['priority'] : 10);
            } catch (Throwable) {
            }
        }

        return DnsDiff::externalMail($records);
    }
}
