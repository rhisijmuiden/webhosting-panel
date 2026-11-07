<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Rhis\DnsMail\Cloudflare\CloudflareException;
use Rhis\DnsMail\Dns\Resolver;
use Rhis\DnsMail\Models\DnsOnboarding;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Notifications\DnsLiveNotification;
use RuntimeException;
use Throwable;

/**
 * The DNS wizard: moves a customer's domain to the RHIS Cloudflare account.
 *
 * 1. start(): add the zone (or reuse it), let Cloudflare copy the records it
 *    finds at the current provider, publish the panel's records.
 * 2. The customer changes the nameservers at their registrar.
 * 3. check(): ask Cloudflare and the public DNS; once Cloudflare activates the
 *    zone the customer gets a mail and a notice in the portal.
 *
 * Nothing changes for visitors before step 2: the old provider keeps
 * answering until the registrar points at Cloudflare.
 */
final class DnsOnboarder
{
    public function __construct(
        private readonly DnsMailConfig $config,
        private readonly DnsSync $dns,
        private readonly Resolver $resolver,
    ) {
    }

    public function accountId(): string
    {
        $id = (string) $this->config->get('cloudflare_account_id', '');
        if ($id !== '') {
            return $id;
        }
        $accounts = $this->dns->client()->listAccounts();
        if (count($accounts) === 1) {
            return $accounts[0]['id'];
        }

        throw new RuntimeException(__('The Cloudflare account for new domains is not set. Ask :provider to finish the Cloudflare settings.', ['provider' => \App\Support\Brand::provider()]));
    }

    /** @return array{onboarding: DnsOnboarding, warnings: list<string>} */
    public function start(MailDomain $domain): array
    {
        $existing = $domain->onboarding;
        if ($existing !== null && !$existing->isPending() && !($existing->status === DnsOnboarding::EXPIRED)) {
            return ['onboarding' => $existing, 'warnings' => []];
        }

        $client = $this->dns->client();
        $zone = $client->findZone($domain->domain);
        $created = false;
        if ($zone === null) {
            try {
                $zone = $client->createZone($domain->domain, $this->accountId());
            } catch (CloudflareException $e) {
                throw new RuntimeException(match (true) {
                    $e->hasCode(1061) => __('This domain is already in another Cloudflare account. Remove it there first, or contact :provider.', ['provider' => \App\Support\Brand::provider()]),
                    $e->hasCode(1049), $e->hasCode(1097) => __('Cloudflare does not accept this domain: is it registered, and is it the main domain (not a subdomain)?'),
                    $e->httpStatus === 403 => __('The Cloudflare token may not add domains. Ask :provider to give it “Zone: Edit”.', ['provider' => \App\Support\Brand::provider()]),
                    default => $e->getMessage(),
                }, 0, $e);
            }
            $created = true;
        } else {
            $zone = $client->getZone($zone['id']);
        }

        $imported = 0;
        if ($created) {
            try {
                $imported = $client->scanRecords($zone['id']);
            } catch (Throwable) {
                $imported = 0; // The customer can still add missing records on the DNS tab.
            }
        }

        $domain->update(['cloudflare_zone_id' => $zone['id'], 'dns_managed' => true, 'dns_check' => null]);
        $onboarding = DnsOnboarding::updateOrCreate(['mail_domain_id' => $domain->id], [
            'zone_id' => $zone['id'],
            'status' => DnsOnboarding::PENDING,
            'name_servers' => $zone['name_servers'],
            'original_name_servers' => $zone['original_name_servers'] ?: $this->resolver->nameservers($domain->domain),
            'original_registrar' => $zone['original_registrar'],
            'imported_records' => $imported,
            'check_count' => 0,
            'last_checked_at' => null,
            'activated_at' => null,
            'notified_at' => null,
            'seen_at' => null,
        ]);
        ActivityLog::record('dns.onboarding_started', $domain->domain, ['zone' => $zone['name'], 'created' => $created, 'imported' => $imported], $domain->customer_id);

        $warnings = [];
        try {
            $plan = $this->dns->apply($domain->fresh());
            if ($plan->conflicts) {
                $warnings[] = __('Some records copied from your current DNS provider differ from what your hosting needs. Check them on the DNS tab before you change the nameservers.');
            }
        } catch (Throwable $e) {
            $warnings[] = __('DNS was not updated: :error', ['error' => $e->getMessage()]);
        }

        // An existing zone (also a parent zone such as example.com for shop.example.com) can already be live.
        $this->check($onboarding, notify: false);

        return ['onboarding' => $onboarding->fresh(), 'warnings' => $warnings];
    }

    /** Look again. Returns true when the zone became active with this check. */
    public function check(DnsOnboarding $o, bool $force = false, bool $notify = true): bool
    {
        if (!$o->isPending()) {
            return false;
        }
        $domain = $o->domain;
        $client = $this->dns->client();
        $current = $this->resolver->nameservers($domain->domain);
        if ($force || $this->nameserversMatch($o, $current)) {
            // Cloudflare otherwise checks on its own, less often.
            $client->activationCheck($o->zone_id);
        }
        try {
            $zone = $client->getZone($o->zone_id);
        } catch (CloudflareException $e) {
            if ($e->httpStatus === 404 || $e->hasCode(1001) || $e->hasCode(7003)) {
                $o->update(['status' => DnsOnboarding::EXPIRED, 'current_name_servers' => $current, 'last_checked_at' => now()]);
                $domain->update(['cloudflare_zone_id' => null, 'dns_status' => 'no_zone']);

                return false;
            }
            throw $e;
        }
        $o->fill([
            'current_name_servers' => $current,
            'last_checked_at' => now(),
            'check_count' => $o->check_count + 1,
            'name_servers' => $zone['name_servers'] ?: $o->name_servers,
        ]);
        if ($zone['status'] === 'active') {
            $o->fill(['status' => DnsOnboarding::ACTIVE, 'activated_at' => now()])->save();
            ActivityLog::record('dns.onboarding_active', $domain->domain, ['checks' => $o->check_count], $domain->customer_id);
            try {
                $this->dns->inspect($domain->fresh()); // refresh the DNS pill
            } catch (Throwable) {
            }
            if ($notify) {
                $this->notify($o);
            }

            return true;
        }
        if ($o->created_at !== null && $o->created_at->diffInDays(now()) >= DnsOnboarding::MAX_DAYS) {
            $o->status = DnsOnboarding::EXPIRED;
        }
        $o->save();

        return false;
    }

    /** @param list<string> $current */
    private function nameserversMatch(DnsOnboarding $o, array $current): bool
    {
        $want = array_map('strtolower', (array) $o->name_servers);
        sort($want);

        return $want !== [] && $want === $current;
    }

    /** Mail every portal user of the customer (or the customer's own address) once. */
    public function notify(DnsOnboarding $o): void
    {
        if ($o->notified_at !== null) {
            return;
        }
        $domain = $o->domain()->with('customer')->first();
        $customer = $domain?->customer;
        if ($customer === null) {
            return;
        }
        $notification = new DnsLiveNotification($domain->domain, $o->name_servers ?? [], $domain->id);
        try {
            $users = User::where('customer_id', $customer->id)->where('role', User::ROLE_CLIENT)->where('is_active', true)->get();
            if ($users->isNotEmpty()) {
                Notification::send($users, $notification);
            } elseif ($customer->email) {
                Notification::route('mail', $customer->email)->notify($notification->locale($customer->locale ?: config('app.locale')));
            }
        } catch (Throwable $e) {
            report($e); // The portal notice still shows; mail is best effort.
        }
        $o->update(['notified_at' => now()]);
    }

    /** Scheduler: check every pending onboarding that is due. */
    public function checkDue(): int
    {
        $done = 0;
        foreach (DnsOnboarding::where('status', DnsOnboarding::PENDING)->with('domain')->get() as $o) {
            if ($o->domain === null || !$o->isDue()) {
                continue;
            }
            try {
                $this->check($o);
                $done++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $done;
    }
}
