<?php

declare(strict_types=1);

namespace Rhis\Certificates;

use App\Models\ActivityLog;
use App\Models\Website;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Rhis\Certificates\Jobs\IssueCertificate;
use Rhis\Certificates\Models\SiteCertificate;
use Rhis\DnsMail\Services\DnsMailConfig;
use Rhis\DnsMail\Services\DnsSync;
use Throwable;

/** Own and wildcard certificates per website, and what the server serves. */
final class CertificateManager
{
    public function __construct(private readonly AgentClient $agent) {}

    public function for(Website $website): SiteCertificate
    {
        return SiteCertificate::firstOrCreate(['website_id' => $website->id], [
            'source' => $website->ssl_status === 'active' ? 'letsencrypt' : ($website->ssl_status === 'imported' ? 'imported' : 'none'),
        ]);
    }

    /** Host names the website answers on (domain and www/aliases). @return list<string> */
    public function hostNames(Website $website): array
    {
        return array_values(array_unique(array_merge([$website->domain], (array) ($website->aliases ?? []))));
    }

    // ---- reading the server -------------------------------------------------

    public function refresh(Website $website): SiteCertificate
    {
        $record = $this->for($website);
        try {
            $this->store($record, $this->agent->call('ssl.info', ['domain' => $website->domain], 60));
        } catch (AgentException $e) {
            $record->update(['last_error' => $e->getMessage(), 'checked_at' => now()]);
        }

        return $record->refresh();
    }

    /** Read every website's certificate from the server in one call. @return Collection<int, SiteCertificate> */
    public function refreshAll(): Collection
    {
        $entries = collect($this->agent->call('ssl.list', [], 180)['certificates'] ?? [])->keyBy('domain');
        $records = collect();
        Website::query()->withoutGlobalScopes()->whereNotIn('status', ['removing'])->orderBy('id')->each(function (Website $w) use ($entries, $records) {
            if ($entries->has($w->domain)) {
                $records->push($this->store($this->for($w), $entries[$w->domain]));
            }
        });

        return $records;
    }

    public function store(SiteCertificate $record, array $entry): SiteCertificate
    {
        $c = $entry['certificate'] ?? null;
        $source = match ($entry['ssl'] ?? null) {
            'active' => 'letsencrypt',
            'wildcard', 'custom', 'imported' => $entry['ssl'],
            default => 'none',
        };
        $notAfter = $c && ! empty($c['not_after']) ? Carbon::parse($c['not_after']) : null;
        $renewed = $notAfter && (! $record->not_after || ! $record->not_after->equalTo($notAfter));
        $record->fill([
            'source' => $c ? $source : 'none',
            'wildcard_of' => $entry['wildcard_of'] ?? null,
            'common_name' => $c['common_name'] ?? null,
            'names' => $c['names'] ?? null,
            'issuer' => $c['issuer'] ?? null,
            'not_before' => $c && ! empty($c['not_before']) ? Carbon::parse($c['not_before']) : null,
            'not_after' => $notAfter,
            'fingerprint' => $c['fingerprint_sha256'] ?? null,
            'self_signed' => (bool) ($c['self_signed'] ?? false),
            'chain_length' => (int) ($c['chain_length'] ?? 0),
            'checked_at' => now(),
        ]);
        if ($renewed) {
            $record->warned_days = null;
        }
        $record->save();

        return $record;
    }

    // ---- own certificate ------------------------------------------------------

    /**
     * Install an uploaded certificate. Throws when it does not cover the
     * website's domain; returns the warnings to show.
     *
     * @return list<string>
     */
    public function installCustom(Website $website, ParsedCertificate $cert): array
    {
        $hosts = $this->hostNames($website);
        $missing = CertificateParser::uncovered($cert->names, $hosts);
        if (in_array($website->domain, $missing, true)) {
            throw new CertificateException(__('This certificate is for :names, not for :domain.', [
                'names' => implode(', ', $cert->names), 'domain' => $website->domain,
            ]));
        }
        $warnings = $cert->warnings;
        if ($missing !== []) {
            $warnings[] = __('The certificate does not cover :names: visitors on that address get a warning.', ['names' => implode(', ', $missing)]);
        }

        $record = $this->for($website);
        try {
            $result = $this->agent->call('ssl.custom', array_filter([
                'domain' => $website->domain,
                'certificate' => $cert->certificate,
                'private_key' => $cert->privateKey,
                'chain' => $cert->chainPem(),
            ]), 120);
        } catch (AgentException $e) {
            throw new CertificateException(__('The server did not accept the certificate: :error', ['error' => $this->reason($e)]));
        }
        $this->store($record, $result);
        $record->fill(['status' => 'idle', 'requested' => null, 'last_error' => null, 'warned_days' => null]);
        // The CSR made in the panel is used up once its certificate is installed.
        if ($record->csr_key && $this->sameKey($record->csr_key, $cert->privateKey)) {
            $record->fill(['csr' => null, 'csr_key' => null, 'csr_created_at' => null]);
        }
        $record->save();
        $website->update(['ssl_status' => 'active']);
        ActivityLog::record('certificate.custom_installed', $website->domain, [
            'names' => $cert->names, 'not_after' => date('Y-m-d', $cert->notAfter), 'issuer' => $cert->issuer,
        ], $website->customer_id);

        return $warnings;
    }

    public function makeCsr(Website $website, string $keyType, array $subject, bool $www = true): SiteCertificate
    {
        $names = [$website->domain];
        if ($www && ! str_starts_with($website->domain, 'www.')) {
            $names[] = 'www.'.$website->domain;
        }
        $made = app(CsrGenerator::class)->make($names, $keyType, $subject);
        $record = $this->for($website);
        $record->update(['csr' => $made['csr'], 'csr_key' => $made['key'], 'csr_created_at' => now()]);
        ActivityLog::record('certificate.csr_created', $website->domain, ['names' => $names, 'key' => $keyType], $website->customer_id);

        return $record;
    }

    public function dropCsr(Website $website): void
    {
        $this->for($website)->update(['csr' => null, 'csr_key' => null, 'csr_created_at' => null]);
    }

    private function sameKey(string $a, string $b): bool
    {
        $ka = openssl_pkey_get_private($a);
        $kb = openssl_pkey_get_private($b);

        return $ka && $kb && (openssl_pkey_get_details($ka)['key'] ?? 1) === (openssl_pkey_get_details($kb)['key'] ?? 2);
    }

    // ---- Let's Encrypt (normal and wildcard) --------------------------------

    /** null when a wildcard can be requested, else the reason why not. */
    public function wildcardBlocker(Website $website): ?string
    {
        if (! class_exists(DnsMailConfig::class) || ! app(DnsMailConfig::class)->hasCloudflare()) {
            return __('Connect Cloudflare first (DNS & mail settings): the wildcard is checked with a DNS record at Cloudflare.');
        }

        return null;
    }

    /** The parent website whose wildcard already covers this subdomain website, if any. */
    public function parentWildcard(Website $website): ?Website
    {
        $parent = $website->parent_id ? Website::find($website->parent_id) : null;
        if (! $parent) {
            return null;
        }
        $record = SiteCertificate::where('website_id', $parent->id)->where('source', 'wildcard')->first();
        if (! $record || CertificateParser::uncovered((array) $record->names, $this->hostNames($website)) !== []) {
            return null;
        }

        return $parent;
    }

    public function request(Website $website, string $kind): SiteCertificate
    {
        if (! in_array($kind, ['wildcard', 'letsencrypt'], true)) {
            throw new CertificateException('Unknown certificate kind');
        }
        if ($kind === 'wildcard' && ! $this->parentWildcard($website) && ($reason = $this->wildcardBlocker($website))) {
            throw new CertificateException($reason);
        }
        $record = $this->for($website);
        if ($record->isBusy()) {
            throw new CertificateException(__('A certificate request for :domain is already running.', ['domain' => $website->domain]));
        }
        $record->update(['status' => 'busy', 'requested' => $kind, 'last_error' => null]);
        ActivityLog::record('certificate.'.$kind.'_requested', $website->domain, customerId: $website->customer_id);
        IssueCertificate::dispatch($record->id);

        return $record;
    }

    /** Runs in the queue: ask the server for the certificate (DNS-01 can take a few minutes). */
    public function run(SiteCertificate $record): void
    {
        $website = $record->website;
        if (! $website) {
            $record->delete();

            return;
        }
        $kind = $record->requested ?: 'letsencrypt';
        try {
            if ($kind === 'wildcard') {
                $params = ['domain' => $website->domain];
                if (! $this->parentWildcard($website)) {
                    $this->checkZone($website);
                    $params['cloudflare_token'] = app(DnsMailConfig::class)->cloudflareToken();
                }
                $result = $this->agent->call('ssl.wildcard', $params, 660);
            } else {
                $result = $this->agent->call('ssl.letsencrypt', ['domain' => $website->domain], 360);
            }
            $this->store($record, $result);
            $record->update(['status' => 'idle', 'requested' => null, 'last_error' => null]);
            $website->update(['ssl_status' => 'active']);
        } catch (Throwable $e) {
            $record->update(['status' => 'failed', 'last_error' => $e instanceof AgentException ? $this->reason($e) : $e->getMessage()]);
            $this->refreshQuietly($website);
        }
    }

    private function checkZone(Website $website): void
    {
        if (! class_exists(DnsSync::class)) {
            return;
        }
        try {
            $zone = app(DnsSync::class)->client()->findZone($website->domain);
        } catch (Throwable $e) {
            throw new CertificateException(__('Cloudflare could not be reached: :error', ['error' => $e->getMessage()]));
        }
        if ($zone === null) {
            throw new CertificateException(__(':domain is not in the Cloudflare account of the panel. Move its DNS to Cloudflare first (DNS wizard).', ['domain' => $website->domain]));
        }
    }

    private function refreshQuietly(Website $website): void
    {
        try {
            $record = $this->for($website);
            $error = $record->last_error;
            $status = $record->status;
            $this->store($record, $this->agent->call('ssl.info', ['domain' => $website->domain], 60));
            $record->update(['last_error' => $error, 'status' => $status]);
        } catch (Throwable) {
        }
    }

    /** The useful part of an agent error (whp-site prints the reason on stderr). */
    private function reason(AgentException $e): string
    {
        $stderr = trim((string) ($e->details['stderr'] ?? ''));
        if ($stderr === '') {
            return $e->getMessage();
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $stderr))));
        $fatal = array_values(array_filter($lines, fn ($l) => str_contains($l, '✗')));
        $line = $fatal ? end($fatal) : end($lines);

        return trim(preg_replace('/\e\[[0-9;]*m|✗/u', '', (string) $line));
    }

    /** Keep the token certbot renewals use in step with the panel's Cloudflare settings. */
    public function syncCloudflareToken(): void
    {
        if (! class_exists(DnsMailConfig::class) || ! SiteCertificate::where('source', 'wildcard')->exists()) {
            return;
        }
        $token = app(DnsMailConfig::class)->cloudflareToken();
        if ($token !== '') {
            $this->agent->call('ssl.cloudflare', ['cloudflare_token' => $token], 30);
        }
    }
}
