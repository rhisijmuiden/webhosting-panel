<?php

declare(strict_types=1);

namespace Rhis\Certificates;

use App\Models\Website;
use App\Services\Agent\FakeAgentClient;

/** ssl.* answers for the fake agent (WHP_AGENT_DRIVER=fake, tests and local development). */
final class FakeResponses
{
    public static function register(FakeAgentClient $agent): void
    {
        $le = function (string $domain, string $ssl = 'active', ?string $base = null, int $days = 64): array {
            $names = $ssl === 'wildcard' ? [$base ?? $domain, '*.'.($base ?? $domain)] : [$domain, 'www.'.$domain];

            return [
                'domain' => $domain, 'ssl' => $ssl, 'wildcard_of' => $ssl === 'wildcard' ? ($base ?? $domain) : null,
                'names' => [$domain, 'www.'.$domain],
                'certificate' => [
                    'subject' => 'CN='.$names[0], 'common_name' => $names[0], 'names' => $names,
                    'issuer' => 'CN=E7,O=Let\'s Encrypt,C=US',
                    'not_before' => now()->subDays(90 - $days)->toIso8601String(), 'not_after' => now()->addDays($days)->toIso8601String(),
                    'days_left' => $days, 'expired' => false, 'self_signed' => false, 'chain_length' => 1,
                    'serial' => '04A1B2C3D4E5F6', 'fingerprint_sha256' => 'AB:CD:EF:01:23:45:67:89',
                ],
            ];
        };
        $info = function (array $p) use ($le) {
            $w = Website::query()->withoutGlobalScopes()->where('domain', $p['domain'] ?? '')->first();
            if (! $w || $w->ssl_status !== 'active') {
                return ['domain' => $p['domain'] ?? '', 'ssl' => $w->ssl_status ?? 'pending', 'certificate' => null, 'names' => [$p['domain'] ?? '']];
            }

            return $le($w->domain);
        };
        $agent->respond('ssl.info', $info);
        $agent->respond('ssl.list', fn () => ['certificates' => Website::query()->withoutGlobalScopes()->get()->map(fn ($w) => $info(['domain' => $w->domain]))->all()]);
        $agent->respond('ssl.custom', function (array $p) {
            $cert = @openssl_x509_read($p['certificate'] ?? '');
            $x = $cert ? openssl_x509_parse($cert) : [];
            $names = [];
            foreach (explode(',', (string) ($x['extensions']['subjectAltName'] ?? '')) as $n) {
                if (str_starts_with(trim($n), 'DNS:')) {
                    $names[] = substr(trim($n), 4);
                }
            }

            return [
                'domain' => $p['domain'] ?? '', 'ssl' => 'custom', 'wildcard_of' => null, 'names' => [$p['domain'] ?? ''],
                'certificate' => [
                    'subject' => 'CN='.($x['subject']['CN'] ?? ''), 'common_name' => $x['subject']['CN'] ?? null, 'names' => $names,
                    'issuer' => 'CN='.($x['issuer']['CN'] ?? ''),
                    'not_before' => date(DATE_ATOM, (int) ($x['validFrom_time_t'] ?? time())),
                    'not_after' => date(DATE_ATOM, (int) ($x['validTo_time_t'] ?? time())),
                    'days_left' => (int) floor((($x['validTo_time_t'] ?? time()) - time()) / 86400), 'expired' => false,
                    'self_signed' => ($x['subject'] ?? 1) === ($x['issuer'] ?? 2), 'chain_length' => substr_count((string) ($p['chain'] ?? ''), 'BEGIN CERTIFICATE'),
                    'serial' => (string) ($x['serialNumberHex'] ?? ''), 'fingerprint_sha256' => strtoupper((string) openssl_x509_fingerprint($cert, 'sha256')),
                ],
            ];
        });
        $agent->respond('ssl.wildcard', fn (array $p) => $le($p['domain'] ?? '', 'wildcard'));
        $agent->respond('ssl.letsencrypt', fn (array $p) => $le($p['domain'] ?? ''));
        $agent->respond('ssl.cloudflare', fn (array $p) => ['stored' => ($p['cloudflare_token'] ?? '') !== '']);
    }
}
