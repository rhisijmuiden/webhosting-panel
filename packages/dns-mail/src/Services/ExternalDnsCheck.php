<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\RecordCheck;
use Rhis\DnsMail\Dns\Resolver;
use Rhis\DnsMail\Models\MailDomain;

/**
 * For domains whose DNS the customer keeps at another provider: looks up
 * every record the domain needs in the public DNS and stores a checklist
 * (green check, "add this" or "change this").
 */
final class ExternalDnsCheck
{
    /** How often the scheduler re-checks a domain on its own. */
    public const INTERVAL_HOURS = 6;

    public function __construct(private readonly DomainRecords $records, private readonly Resolver $resolver)
    {
    }

    /** @return array{checked_at: string, ok: bool, results: list<array{type: string, name: string, value: string, status: string, found: list<string>}>} */
    public function run(MailDomain $domain): array
    {
        $results = (new RecordCheck($this->resolver))->check($this->records->all($domain));
        $out = [
            'checked_at' => now()->toIso8601String(),
            'ok' => RecordCheck::allOk($results),
            'results' => array_map(static fn (array $r): array => [
                'type' => $r['record']->type,
                'name' => $r['record']->relativeName($domain->domain),
                'value' => $r['record']->displayValue(),
                'status' => $r['status'],
                'found' => array_map(static fn (DnsRecord $f): string => $f->type . ' ' . $f->displayValue(), $r['found']),
            ], $results),
        ];
        $domain->update([
            'dns_check' => $out,
            'dns_status' => $out['ok'] ? 'in_sync' : 'missing',
            'dns_error' => null,
            'dns_checked_at' => now(),
        ]);

        return $out;
    }

    /** Scheduler: re-check domains with DNS elsewhere every few hours. */
    public function checkDue(): int
    {
        $done = 0;
        MailDomain::where('dns_managed', false)
            ->where(fn ($q) => $q->whereNull('dns_checked_at')->orWhere('dns_checked_at', '<', now()->subHours(self::INTERVAL_HOURS)))
            ->with('website')
            ->each(function (MailDomain $d) use (&$done): void {
                $this->run($d);
                $done++;
            });

        return $done;
    }
}
