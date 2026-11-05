<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Models\ActivityLog;
use Rhis\DnsMail\Cloudflare\CloudflareClient;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\SyncPlan;
use Rhis\DnsMail\Dns\SyncPlanner;
use Rhis\DnsMail\Http\HttpClient;
use Rhis\DnsMail\Models\MailDomain;
use RuntimeException;
use Throwable;

/** Compares a domain with Cloudflare, applies changes and manages the customer's own records. */
final class DnsSync
{
    public function __construct(
        private readonly DnsMailConfig $config,
        private readonly DomainRecords $records,
        private readonly HttpClient $http,
        private readonly SyncPlanner $planner = new SyncPlanner(),
    ) {
    }

    public function client(): CloudflareClient
    {
        $token = $this->config->cloudflareToken();
        if ($token === '') {
            throw new RuntimeException(__('Cloudflare is not connected yet.'));
        }

        return new CloudflareClient($this->http, $token);
    }

    public function zoneId(MailDomain $d): ?string
    {
        if ($d->cloudflare_zone_id) {
            return $d->cloudflare_zone_id;
        }
        $zone = $this->client()->findZone($d->domain);
        if ($zone === null) {
            $d->update(['dns_status' => 'no_zone', 'dns_checked_at' => now(), 'dns_error' => null]);

            return null;
        }
        $d->update(['cloudflare_zone_id' => $zone['id']]);

        return $zone['id'];
    }

    /** @return array{plan: ?SyncPlan, existing: list<DnsRecord>, error: ?string} */
    public function inspect(MailDomain $d, bool $adopt = false): array
    {
        try {
            $zoneId = $this->zoneId($d);
            if ($zoneId === null) {
                return ['plan' => null, 'existing' => [], 'error' => __('This domain is not in the connected Cloudflare account.')];
            }
            $existing = $this->client()->listRecords($zoneId);
            $plan = $this->planner->plan($d->domain, $this->records->all($d), $existing, $adopt, $d->provider);
            if (!$adopt) {
                $d->update([
                    'dns_status' => $plan->conflicts ? 'conflict' : ($plan->changes ? 'changes' : 'in_sync'),
                    'dns_error' => null,
                    'dns_checked_at' => now(),
                ]);
            }

            return ['plan' => $plan, 'existing' => $existing, 'error' => null];
        } catch (Throwable $e) {
            $d->update(['dns_status' => 'error', 'dns_error' => $e->getMessage(), 'dns_checked_at' => now()]);

            return ['plan' => null, 'existing' => [], 'error' => $e->getMessage()];
        }
    }

    public function apply(MailDomain $d, bool $adopt = false): SyncPlan
    {
        $zoneId = $this->zoneId($d) ?? throw new RuntimeException(__('This domain is not in the connected Cloudflare account.'));
        $client = $this->client();
        $plan = $this->planner->plan($d->domain, $this->records->all($d), $client->listRecords($zoneId), $adopt, $d->provider);
        $client->batch(
            $zoneId,
            array_map(fn ($c) => $c->record, $plan->ofAction('create')),
            array_map(fn ($c) => $c->record, $plan->ofAction('update')),
            array_map(fn ($c) => (string) $c->record->id, $plan->ofAction('delete')),
        );
        $d->update([
            'dns_status' => $plan->conflicts ? 'conflict' : 'in_sync',
            'dns_error' => null,
            'dns_checked_at' => now(),
        ]);
        ActivityLog::record('dns.applied', $d->domain, ['changes' => count($plan->changes), 'adopt' => $adopt], $d->customer_id);

        return $plan;
    }

    /** Add a record the customer manages (not marked as managed, so sync never touches it). */
    public function addOwnRecord(MailDomain $d, DnsRecord $record): void
    {
        $zoneId = $this->zoneId($d) ?? throw new RuntimeException(__('This domain is not in the connected Cloudflare account.'));
        if (!str_ends_with('.' . $record->name, '.' . $d->domain)) {
            throw new RuntimeException(__('The record must be inside :domain.', ['domain' => $d->domain]));
        }
        $this->client()->createRecord($zoneId, $record);
        ActivityLog::record('dns.record_added', $record->type . ' ' . $record->name, [], $d->customer_id);
    }

    public function deleteOwnRecord(MailDomain $d, string $recordId): void
    {
        $zoneId = $this->zoneId($d) ?? throw new RuntimeException(__('This domain is not in the connected Cloudflare account.'));
        foreach ($this->client()->listRecords($zoneId) as $r) {
            if ($r->id === $recordId) {
                if ($r->isManaged()) {
                    throw new RuntimeException(__('This record is managed by the panel. Change the mail or website settings instead.'));
                }
                $this->client()->deleteRecord($zoneId, $recordId);
                ActivityLog::record('dns.record_deleted', $r->type . ' ' . $r->name, [], $d->customer_id);

                return;
            }
        }
        throw new RuntimeException(__('Record not found.'));
    }
}
