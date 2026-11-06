<?php

declare(strict_types=1);

namespace Rhis\Migration\Services;

use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\RecordScope;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Services\DnsMailConfig;
use Rhis\DnsMail\Services\DnsSync;
use Rhis\DnsMail\Services\DomainRecords;
use Rhis\Migration\Models\MigrationItem;
use Throwable;

/**
 * The switch: point the domain at this server.
 *
 * - The customer's own records from the old server (Google verification,
 *   extra subdomains, SRV, ...) are added to Cloudflare as their own records.
 * - Records the panel manages itself (web, MX, SPF, DKIM, DMARC, autodiscover)
 *   are not copied; the panel writes its own and takes over the old ones.
 * - Records that pointed at the old server's IP are left out.
 * Without Cloudflare the result is a BIND zone file to load at the DNS host.
 */
final class DnsCutover
{
    public function __construct(
        private readonly DomainRecords $records,
        private readonly DnsSync $dns,
        private readonly DnsMailConfig $config,
        private readonly AgentClient $agent,
    ) {}

    /** @return array{mode: string, imported: int, skipped: list<string>, zone?: string, conflicts?: int, error?: string} */
    public function run(MigrationItem $item): array
    {
        $domain = MailDomain::where('domain', $item->domain)->firstOrFail();
        $desired = $this->records->all($domain);
        [$import, $skipped] = self::importable($item->source_data, $desired);

        $result = ['mode' => 'manual', 'imported' => 0, 'skipped' => $skipped];
        $zoneId = null;
        if ($this->config->hasCloudflare()) {
            try {
                $zoneId = $this->dns->zoneId($domain);
            } catch (Throwable $e) {
                $result['error'] = $e->getMessage();
            }
        }

        if ($zoneId === null) {
            $domain->update(['dns_managed' => false]);
            $result['zone'] = self::zoneFile($item->domain, [...$desired, ...$import]);
        } else {
            $existing = RecordScope::forDomain($domain->domain, $this->dns->client()->listRecords($zoneId));
            foreach ($import as $r) {
                if (self::present($r, $existing)) {
                    continue;
                }
                try {
                    $this->dns->addOwnRecord($domain, $r);
                    $result['imported']++;
                } catch (Throwable $e) {
                    $result['skipped'][] = $r->type.' '.$r->name.': '.$e->getMessage();
                }
            }
            $domain->update(['dns_managed' => true]);
            $plan = $this->dns->apply($domain->fresh(), adopt: true);
            $result['mode'] = 'cloudflare';
            $result['conflicts'] = count($plan->conflicts);
        }

        // Let's Encrypt as soon as DNS reaches this server; the retry timer keeps trying until then.
        try {
            $site = $this->agent->call('site.ssl', ['domain' => $item->domain], 300);
            $item->website?->update(['ssl_status' => $site['ssl'] ?? 'pending']);
        } catch (Throwable) {
        }

        $item->update(['status' => 'live', 'cutover_at' => now(), 'result' => ($item->result ?? []) + ['dns' => $result]]);
        ActivityLog::record('migration.dns_switched', $item->domain, ['mode' => $result['mode'], 'imported' => $result['imported']], $item->customer_id);

        return $result;
    }

    /**
     * Split the old server's records into the ones to keep and the ones the panel replaces.
     *
     * @param  array<string, mixed>  $source  migration item source_data
     * @param  list<DnsRecord>  $desired  records the panel manages for the domain
     * @return array{0: list<DnsRecord>, 1: list<string>}
     */
    public static function importable(array $source, array $desired): array
    {
        $domain = strtolower((string) $source['name']);
        $oldIps = array_map('strtolower', $source['server_ips'] ?? []);
        $managed = [];
        foreach ($desired as $r) {
            $managed[$r->type.'|'.$r->name] = true;
        }
        $managedNames = array_fill_keys(array_map(fn (DnsRecord $r) => $r->name, $desired), true);

        $keep = [];
        $skipped = [];
        foreach ($source['dns'] ?? [] as $rec) {
            $type = strtoupper((string) ($rec['type'] ?? ''));
            $name = DnsRecord::normaliseName((string) ($rec['name'] ?? ''));
            $content = (string) ($rec['content'] ?? '');
            $label = $type.' '.$name;
            $reason = match (true) {
                $name !== $domain && ! str_ends_with($name, '.'.$domain) => 'outside the domain',
                // TXT shares names with verification records: only the panel's own kinds are replaced (below).
                $type !== 'TXT' && isset($managed[$type.'|'.$name]) => 'managed by the panel',
                $type === 'CNAME' && isset($managedNames[$name]) => 'managed by the panel',
                in_array($type, ['A', 'AAAA'], true) && in_array(strtolower($content), $oldIps, true) => 'points to the old server',
                $type === 'MX' => 'mail is handled by the panel',
                $type === 'TXT' && str_starts_with(strtolower($content), 'v=spf1') && $name === $domain => 'managed by the panel (SPF)',
                $type === 'TXT' && preg_match('/^(_dmarc|_mta-sts|_smtp\._tls|[^.]+\._domainkey|_bimi|default\._bimi)\./', $name) === 1 => 'managed by the panel',
                in_array(explode('.', $name)[0], ['autodiscover', 'autoconfig', 'webmail', 'mail', 'smtp', 'imap', 'pop', 'pop3', 'ftp', 'ns1', 'ns2', 'lists', 'ipv4', 'ipv6'], true)
                    && in_array($type, ['A', 'AAAA', 'CNAME'], true) => 'Plesk service name',
                default => null,
            };
            if ($reason !== null) {
                $skipped[] = "{$label}: {$reason}";

                continue;
            }
            try {
                $keep[] = new DnsRecord($type, $name, $content, priority: isset($rec['priority']) ? (int) $rec['priority'] : null,
                    srv: $rec['srv'] ?? null, proxied: in_array($type, ['A', 'AAAA', 'CNAME'], true) ? false : null);
            } catch (Throwable $e) {
                $skipped[] = "{$label}: {$e->getMessage()}";
            }
        }

        return [$keep, $skipped];
    }

    /** @param list<DnsRecord> $existing */
    private static function present(DnsRecord $r, array $existing): bool
    {
        foreach ($existing as $e) {
            if ($e->type === $r->type && $e->name === $r->name && strcasecmp($e->content, $r->content) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param list<DnsRecord> $records */
    public static function zoneFile(string $domain, array $records): string
    {
        $lines = ['$ORIGIN '.$domain.'.', '$TTL 3600'];
        foreach ($records as $r) {
            $name = $r->name === $domain ? '@' : substr($r->name, 0, -strlen($domain) - 1);
            $content = match ($r->type) {
                'TXT' => '"'.str_replace('"', '\"', $r->content).'"',
                'MX' => ($r->priority ?? 10).' '.rtrim($r->content, '.').'.',
                'CNAME' => rtrim($r->content, '.').'.',
                'SRV' => ($r->srv['priority'] ?? $r->priority ?? 0).' '.($r->srv['weight'] ?? 0).' '.($r->srv['port'] ?? 0).' '.rtrim((string) ($r->srv['target'] ?? ''), '.').'.',
                default => $r->content,
            };
            $lines[] = sprintf('%-30s IN %-6s %s', $name, $r->type, $content);
        }

        return implode("\n", $lines)."\n";
    }
}
