<?php

declare(strict_types=1);

namespace Rhis\Migration\Wizard;

use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\RecordScope;
use Rhis\DnsMail\Dns\SyncChange;
use Rhis\DnsMail\Dns\SyncPlanner;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Services\DnsSync;
use Rhis\DnsMail\Services\DomainRecords;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Services\DnsCutover;

/**
 * Compares what Cloudflare has for a domain now with what this server needs,
 * plus the domain's own records from the old server, as a list the admin
 * ticks line by line before anything is changed.
 */
final class DnsDiff
{
    /** Hosts of services that live outside this server: replacing them breaks that service. */
    private const EXTERNAL = [
        'Microsoft 365' => '/(outlook\.com|office365|microsoft|onmicrosoft|protection\.outlook|lync|msappproxy)/i',
        'Google Workspace' => '/(google\.com|googlemail|_spf\.google|gmr-smtp|ghs\.googlehosted)/i',
        'Zoho' => '/zoho/i',
        'Mailgun' => '/mailgun/i',
        'SendGrid' => '/sendgrid/i',
        'Mimecast' => '/mimecast/i',
        'Proton' => '/proton/i',
        'Mailchimp' => '/(mailchimp|mcsv\.net|mandrill)/i',
    ];

    public function __construct(
        private readonly DnsSync $dns,
        private readonly DomainRecords $records,
        private readonly SyncPlanner $planner = new SyncPlanner,
    ) {}

    /** Records of the zone that belong to this domain. @return list<DnsRecord> */
    public function current(MailDomain $domain, string $zoneId): array
    {
        $nested = MailDomain::query()->where('domain', 'like', '%.'.$domain->domain)->pluck('domain')->all();

        return RecordScope::forDomain($domain->domain, $this->dns->client()->listRecords($zoneId), $nested);
    }

    /**
     * @param  list<DnsRecord>  $existing  what Cloudflare has now (scoped to the domain)
     * @return array{rows: list<DnsRow>, skipped: list<string>}
     */
    public function rows(MigrationItem $item, MailDomain $domain, array $existing): array
    {
        $zone = $domain->domain;
        $desired = $this->records->all($domain);
        $plan = $this->planner->plan($zone, $desired, $existing, adopt: true, note: $domain->provider);
        [$import, $skipped] = DnsCutover::importable($item->source_data ?? ['name' => $zone], $desired);

        $rows = [];
        $touched = [];
        foreach ($plan->changes as $c) {
            $old = $c->action === SyncChange::DELETE ? $c->record : $c->previous;
            $new = $c->action === SyncChange::DELETE ? null : $c->record;
            if ($old?->id) {
                $touched[$old->id] = true;
            }
            $service = $old ? self::externalService($old) : null;
            $hint = match (true) {
                $service !== null => 'external',
                $c->action === SyncChange::UPDATE && $old !== null && $new !== null && $old->fingerprint() === $new->fingerprint() && ! $old->isManaged() => 'adopt',
                $old !== null && ! $old->isManaged() => 'replace',
                default => null,
            };
            $rows[] = new DnsRow($c->action, self::group($c->record, $zone), $new, $old, 'panel', $service === null, $hint === 'external' ? 'external:'.$service : $hint);
        }
        foreach ($plan->unchanged as $r) {
            $touched[(string) $r->id] = true;
            $rows[] = new DnsRow('same', self::group($r, $zone), $r, $r, 'panel', false);
        }

        foreach ($import as $r) {
            if (self::find($existing, fn (DnsRecord $e) => $e->fingerprint() === $r->fingerprint()) !== null) {
                continue; // Already in Cloudflare: shows up as untouched below.
            }
            // Only names that hold one value clash: a CNAME, or an address of the same type. TXT, SRV and CAA can sit side by side.
            $clash = self::find($existing, fn (DnsRecord $e) => ! isset($touched[(string) $e->id]) && $e->name === $r->name
                && SyncPlanner::slotOf($e) === SyncPlanner::slotOf($r) && str_ends_with(SyncPlanner::slotOf($r), '|address')
                && ($e->type === $r->type || $e->type === 'CNAME' || $r->type === 'CNAME'));
            if ($clash !== null) {
                $touched[(string) $clash->id] = true;
                $rows[] = new DnsRow('update', 'own', $r->with(id: $clash->id), $clash, 'plesk', false, 'cloudflare_differs');
            } else {
                $rows[] = new DnsRow('create', 'own', $r, null, 'plesk', true);
            }
        }

        foreach ($existing as $e) {
            if (! isset($touched[(string) $e->id])) {
                $rows[] = new DnsRow('untouched', self::group($e, $zone), null, $e, 'cloudflare', false);
            }
        }

        $order = ['web' => 0, 'mail' => 1, 'own' => 2, 'other' => 3];
        usort($rows, fn (DnsRow $a, DnsRow $b) => [$order[$a->group] ?? 9, $a->isChange() ? 0 : 1, $a->name($zone), $a->type()]
            <=> [$order[$b->group] ?? 9, $b->isChange() ? 0 : 1, $b->name($zone), $b->type()]);

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    public static function externalService(DnsRecord $r): ?string
    {
        $text = $r->type === 'SRV' ? (string) ($r->srv['target'] ?? '') : $r->content;
        foreach (self::EXTERNAL as $name => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $name;
            }
        }

        return null;
    }

    /** Which mail provider the old DNS points at, if not a mail server of its own. */
    public static function externalMail(array $records): ?string
    {
        foreach ($records as $r) {
            if ($r instanceof DnsRecord && $r->type === 'MX' && ($s = self::externalService($r)) !== null) {
                return $s;
            }
        }

        return null;
    }

    public static function group(DnsRecord $r, string $zone): string
    {
        $kind = explode('|', SyncPlanner::slotOf($r))[1];
        $first = explode('.', $r->relativeName($zone))[0];
        if (in_array($kind, ['mx', 'spf', 'dmarc', 'dkim', 'tlsa', 'bimi', 'ms-verify', 'srv'], true)
            || in_array($first, ['mail', 'smtp', 'imap', 'pop', 'pop3', 'webmail', 'autoconfig', 'autodiscover', 'mta-sts', '_mta-sts', '_smtp', '_dmarc', 'enterpriseregistration', 'enterpriseenrollment'], true)
            || str_contains($r->name, '._domainkey.')) {
            return 'mail';
        }

        return in_array($kind, ['address', 'caa'], true) ? 'web' : 'other';
    }

    /**
     * @param  list<DnsRecord>  $records
     */
    private static function find(array $records, callable $match): ?DnsRecord
    {
        foreach ($records as $r) {
            if ($match($r)) {
                return $r;
            }
        }

        return null;
    }
}
