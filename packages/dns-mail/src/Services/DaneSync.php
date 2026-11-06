<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Models\ActivityLog;
use App\Services\Agent\AgentClient;
use Rhis\DnsMail\Dns\DnsRecord;
use Rhis\DnsMail\Dns\RecordScope;
use Rhis\DnsMail\Dns\SyncPlan;
use Rhis\DnsMail\Dns\SyncPlanner;
use RuntimeException;

/**
 * DANE for the mail host: a TLSA record at _25._tcp.<mail host> with the hash
 * of the key Postfix presents. Sending servers that check DANE then refuse a
 * fake certificate. Needs DNSSEC on the zone, and a key that survives
 * renewals (certbot --reuse-key), or mail from those servers would bounce.
 */
final class DaneSync
{
    public function __construct(
        private readonly DnsMailConfig $config,
        private readonly DnsSync $dns,
        private readonly AgentClient $agent,
        private readonly SyncPlanner $planner = new SyncPlanner(),
    ) {
    }

    public function recordName(): string
    {
        return '_25._tcp.' . $this->config->mailHost();
    }

    /**
     * What the server and Cloudflare say, without changing anything.
     *
     * @return array{tlsa: array, zone: ?array, dnssec: ?string, problems: list<string>, desired: list<DnsRecord>}
     */
    public function status(): array
    {
        $problems = [];
        $tlsa = $this->agent->call('mail.tlsa', [], 30);
        if (($tlsa['reuse_key'] ?? null) === false) {
            $problems[] = __('The certificate of :host gets a new key at every renewal. Renew it once with --reuse-key first, or DANE breaks every 60 days.', ['host' => $this->config->mailHost()]);
        }
        $client = $this->dns->client();
        $zone = $client->findZone($this->config->mailHost());
        $dnssec = null;
        if ($zone === null) {
            $problems[] = __(':host is not in the connected Cloudflare account.', ['host' => $this->config->mailHost()]);
        } else {
            $dnssec = $client->dnssecStatus($zone['id']);
            if ($dnssec !== 'active') {
                $problems[] = __('DNSSEC is not active for :zone. Turn it on in Cloudflare (DNS > Settings) and add the DS record at the registrar.', ['zone' => $zone['name']]);
            }
        }
        $desired = [new DnsRecord('TLSA', $this->recordName(), sprintf('%d %d %d %s', $tlsa['usage'], $tlsa['selector'], $tlsa['matching_type'], $tlsa['certificate']))];

        return ['tlsa' => $tlsa, 'zone' => $zone, 'dnssec' => $dnssec, 'problems' => $problems, 'desired' => $desired];
    }

    /** Publish (enabled) or withdraw (disabled) the TLSA record. */
    public function apply(bool $enabled): SyncPlan
    {
        $status = $this->status();
        if ($enabled && $status['problems'] !== []) {
            throw new RuntimeException(implode(' ', $status['problems']));
        }
        $zone = $status['zone'] ?? throw new RuntimeException(implode(' ', $status['problems']));
        $client = $this->dns->client();
        $existing = RecordScope::atNames($client->listRecords($zone['id']), [$this->recordName()], 'TLSA');
        $plan = $this->planner->plan($zone['name'], $enabled ? $status['desired'] : [], $existing, false, RecordScope::SERVER_NOTE);
        $client->batch(
            $zone['id'],
            array_map(fn ($c) => $c->record, $plan->ofAction('create')),
            array_map(fn ($c) => $c->record, $plan->ofAction('update')),
            array_map(fn ($c) => (string) $c->record->id, $plan->ofAction('delete')),
        );
        if ($plan->changes !== []) {
            ActivityLog::record('dns.dane_' . ($enabled ? 'published' : 'withdrawn'), $this->recordName(), ['changes' => count($plan->changes)]);
        }

        return $plan;
    }
}
