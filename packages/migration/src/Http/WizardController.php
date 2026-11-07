<?php

declare(strict_types=1);

namespace Rhis\Migration\Http;

use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Rhis\DnsMail\Models\MailDomain;
use Rhis\DnsMail\Services\DnsMailConfig;
use Rhis\DnsMail\Services\DnsOnboarder;
use Rhis\DnsMail\Services\DnsSync;
use Rhis\DnsMail\Services\DomainProvisioner;
use Rhis\Migration\Models\MigrationDnsSnapshot;
use Rhis\Migration\Models\MigrationItem;
use Rhis\Migration\Services\DnsCutover;
use Rhis\Migration\Wizard\DnsDiff;
use Rhis\Migration\Wizard\DnsRow;
use Rhis\Migration\Wizard\DnsSwitch;
use Rhis\Migration\Wizard\WizardSteps;
use Throwable;

/** Per-domain migration wizard: walk through every part, then switch DNS after a final check. */
final class WizardController extends Controller
{
    public function __construct(private readonly DnsMailConfig $config) {}

    public function show(MigrationItem $item, ?string $step = null): View|RedirectResponse
    {
        $item->load('source', 'customer', 'website', 'dnsSnapshots');
        $domain = MailDomain::where('domain', $item->domain)->first();
        $steps = WizardSteps::all($item, $domain);
        if ($step === null) {
            $step = collect($steps)->first(fn ($s) => ! $s['confirmed'])['key'] ?? 'final';
        }
        abort_unless(isset($steps[$step]), 404);

        $dns = null;
        if (in_array($step, ['dns', 'final'], true) && $domain !== null) {
            // Reading Cloudflare can learn the zone (or that there is none): count that in the checks.
            $dns = $this->dnsView($item, $domain);
            $steps = WizardSteps::all($item, $domain->refresh());
        }

        return view('migration::admin.wizard.show', [
            'item' => $item, 'domain' => $domain, 'steps' => $steps, 'step' => $step, 'dns' => $dns,
            'serverIp' => $this->serverIp(), 'secrets' => $item->secrets ?? [],
            'externalMail' => WizardSteps::externalMail($item),
            'hasCloudflare' => $this->config->hasCloudflare(),
        ]);
    }

    /** @return array<string, mixed> */
    private function dnsView(MigrationItem $item, MailDomain $domain): array
    {
        if (! $this->config->hasCloudflare()) {
            return ['mode' => 'manual', 'zone' => $this->zoneFile($item, $domain)];
        }
        try {
            $sync = app(DnsSync::class);
            $zoneId = $sync->zoneId($domain);
            if ($zoneId === null) {
                return ['mode' => 'no_zone', 'zone' => $this->zoneFile($item, $domain)];
            }
            $diff = app(DnsDiff::class);
            $result = $diff->rows($item, $domain, $diff->current($domain, $zoneId));
            $saved = $item->wizardValue('dns.keys');
            $changeKeys = array_map(fn (DnsRow $r) => $r->key(), array_filter($result['rows'], fn (DnsRow $r) => $r->isChange()));
            $stale = $saved !== null && array_diff($saved, $changeKeys) !== [];

            return ['mode' => 'cloudflare', 'rows' => $result['rows'], 'skipped' => $result['skipped'], 'saved' => $saved,
                'stale' => $stale, 'zone_status' => $this->zoneStatus($sync, $zoneId), 'name_servers' => $item->wizardValue('zone.name_servers', [])];
        } catch (Throwable $e) {
            return ['mode' => 'error', 'error' => $e->getMessage()];
        }
    }

    private function zoneStatus(DnsSync $sync, string $zoneId): ?string
    {
        try {
            return $sync->client()->getZone($zoneId)['status'] ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function zoneFile(MigrationItem $item, MailDomain $domain): string
    {
        $desired = app(\Rhis\DnsMail\Services\DomainRecords::class)->all($domain);
        [$import] = DnsCutover::importable($item->source_data ?? ['name' => $item->domain], $desired);

        return DnsCutover::zoneFile($item->domain, [...$desired, ...$import]);
    }

    public function confirm(Request $request, MigrationItem $item, string $step): RedirectResponse
    {
        abort_unless(in_array($step, WizardSteps::KEYS, true) && $step !== 'final', 404);
        if ($request->boolean('undo')) {
            $item->setWizard("confirmed.$step", null)->save();

            return redirect()->route('admin.migration.wizard.show', [$item, $step]);
        }
        if ($step === 'dns') {
            $data = $request->validate(['keys' => ['array'], 'keys.*' => ['string', 'size:16'], 'total' => ['required', 'integer', 'min:0']]);
            $item->setWizard('dns', ['keys' => array_values(array_unique($data['keys'] ?? [])), 'total' => (int) $data['total'], 'at' => now()->toIso8601String()]);
        }
        $item->setWizard("confirmed.$step", ['at' => now()->toIso8601String(), 'by' => $request->user()?->email])->save();
        $item->log('Wizard: step “:step” confirmed.', ['step' => $step]);
        $item->save();
        $next = WizardSteps::KEYS[array_search($step, WizardSteps::KEYS, true) + 1] ?? 'final';

        return redirect()->route('admin.migration.wizard.show', [$item, $next])->with('status', __('Step confirmed.'));
    }

    /** Ask this server for the website, the way visitors will reach it after the switch. */
    public function testWebsite(MigrationItem $item): RedirectResponse
    {
        $ip = $this->serverIp() ?: '127.0.0.1';
        $host = $item->domain;
        $result = ['at' => now()->toIso8601String(), 'status' => 0, 'error' => null];
        try {
            $response = Http::withOptions([
                'verify' => false, 'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["$host:443:$ip", "$host:80:$ip"]],
            ])->timeout(15)->get("https://$host/");
            $result['status'] = $response->status();
            $result['location'] = $response->header('Location') ?: null;
        } catch (Throwable $e) {
            $result['error'] = mb_substr($e->getMessage(), 0, 300);
        }
        $item->setWizard('http', $result);
        $item->log('Wizard: website test on this server: :result', ['result' => $result['error'] ?? 'HTTP '.$result['status']], $result['error'] || $result['status'] >= 500 ? 'warn' : 'info');
        $item->save();

        return redirect()->route('admin.migration.wizard.show', [$item, 'website']);
    }

    public function mailProvider(Request $request, MigrationItem $item, DomainProvisioner $provisioner): RedirectResponse
    {
        $provider = $request->validate(['provider' => ['required', Rule::in(['rhis', 'external'])]])['provider'];
        $domain = MailDomain::where('domain', $item->domain)->firstOrFail();
        if ($domain->provider !== $provider) {
            $domain->update(['provider' => $provider]);
            $warnings = $provisioner->afterChange($domain->fresh(), withDkim: $provider === 'rhis', syncDns: false);
            $item->setWizard('dns', null)->setWizard('confirmed.dns', null);
            $item->log($provider === 'rhis' ? 'Wizard: mail moves to this server.' : 'Wizard: mail stays at the current provider.');
            $item->save();
            ActivityLog::record('migration.wizard_mail', $item->domain, ['provider' => $provider], $item->customer_id);
            foreach ($warnings as $w) {
                session()->flash('warning', $w);
            }
        }

        return redirect()->route('admin.migration.wizard.show', [$item, 'mail'])->with('status', __('Mail choice saved. Check the DNS step again.'));
    }

    /** Add the domain to Cloudflare (records copied from the current DNS), without changing anything yet. */
    public function addZone(MigrationItem $item, DnsSync $sync, DnsOnboarder $onboarder): RedirectResponse
    {
        $domain = MailDomain::where('domain', $item->domain)->firstOrFail();
        try {
            $client = $sync->client();
            $zone = $client->findZone($domain->domain) ?? $client->createZone($domain->domain, $onboarder->accountId());
            try {
                $client->scanRecords($zone['id']);
            } catch (Throwable) {
            }
            $domain->update(['cloudflare_zone_id' => $zone['id']]);
            $item->setWizard('zone', ['id' => $zone['id'], 'name_servers' => $zone['name_servers'] ?? []])->setWizard('dns', null);
            $item->log('Wizard: domain added to Cloudflare.');
            $item->save();
        } catch (Throwable $e) {
            return back()->withErrors(['wizard' => $e->getMessage()]);
        }

        return redirect()->route('admin.migration.wizard.show', [$item, 'dns'])->with('status', __('The domain is in Cloudflare now. Nothing is switched yet.'));
    }

    public function switch(Request $request, MigrationItem $item, DnsSwitch $switch, DnsCutover $cutover): RedirectResponse
    {
        $request->validate([
            'confirm_domain' => ['required', 'string'],
            'understood' => ['accepted'],
        ], ['understood.accepted' => __('Confirm that you checked everything.')]);
        if (strtolower(trim((string) $request->input('confirm_domain'))) !== $item->domain) {
            return back()->withErrors(['confirm_domain' => __('Type :domain to confirm.', ['domain' => $item->domain])]);
        }
        $domain = MailDomain::where('domain', $item->domain)->first();
        $blocking = WizardSteps::blocking($item, WizardSteps::all($item, $domain));
        if ($blocking !== []) {
            return back()->withErrors(['wizard' => $blocking[0]]);
        }
        try {
            if ($this->config->hasCloudflare() && $domain?->cloudflare_zone_id) {
                $result = $switch->apply($item, $item->wizardValue('dns.keys', []));
                $message = __('DNS of :domain now points to this server: :changed changes, :kept records kept.', ['domain' => $item->domain, 'changed' => $result['changed'], 'kept' => $result['kept']]);
            } else {
                $cutover->run($item);
                $message = __('Load the zone file below at your DNS provider to finish the switch.');
            }
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['wizard' => $e->getMessage()]);
        }

        return redirect()->route('admin.migration.wizard.show', [$item, 'final'])->with('status', $message);
    }

    public function rollback(MigrationItem $item, MigrationDnsSnapshot $snapshot, DnsSwitch $switch): RedirectResponse
    {
        abort_unless($snapshot->migration_item_id === $item->id, 404);
        try {
            $n = $switch->rollback($snapshot);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['wizard' => $e->getMessage()]);
        }

        return redirect()->route('admin.migration.wizard.show', [$item, 'final'])
            ->with('status', __('The old DNS records are back (:count changes). The domain points to the old server again.', ['count' => $n]));
    }

    private function serverIp(): ?string
    {
        try {
            return $this->config->server()->ipv4;
        } catch (Throwable) {
            return null;
        }
    }
}
