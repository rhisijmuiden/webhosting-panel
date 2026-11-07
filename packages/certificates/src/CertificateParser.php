<?php

declare(strict_types=1);

namespace Rhis\Certificates;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;

/**
 * Reads and checks an uploaded certificate before it goes to the server:
 * PEM (certificate, private key, intermediates) or a PFX/PKCS#12 file.
 * Hard errors throw CertificateException; softer problems become warnings.
 */
final class CertificateParser
{
    private const PEM_CERT = '/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/';

    /** @param string|null $passphrase for an encrypted private key */
    public function fromPem(string $certificate, string $privateKey, ?string $chain = null, ?string $passphrase = null): ParsedCertificate
    {
        $certs = $this->pemCertificates($certificate);
        if ($certs === []) {
            throw new CertificateException(__('The certificate is not a PEM certificate (it starts with -----BEGIN CERTIFICATE-----).'));
        }
        $extra = $chain !== null && trim($chain) !== '' ? $this->pemCertificates($chain) : [];
        if ($chain !== null && trim($chain) !== '' && $extra === []) {
            throw new CertificateException(__('The intermediate certificates are not PEM certificates.'));
        }
        if (trim($privateKey) === '') {
            throw new CertificateException(__('The private key is missing.'));
        }
        $key = @openssl_pkey_get_private($privateKey, $passphrase ?? '');
        if ($key === false) {
            throw new CertificateException(str_contains($privateKey, 'ENCRYPTED')
                ? __('The private key is protected with a password. Enter the correct password.')
                : __('The private key could not be read. Paste the key that starts with -----BEGIN PRIVATE KEY-----.'));
        }

        return $this->check(array_shift($certs), $key, array_merge($certs, $extra));
    }

    public function fromPfx(string $contents, ?string $password = null): ParsedCertificate
    {
        $data = [];
        if (! @openssl_pkcs12_read($contents, $data, $password ?? '')) {
            throw new CertificateException(__('The PFX file could not be opened. Check the password; very old PFX files (RC2 encryption) must be exported again, or upload the PEM files.'));
        }
        if (empty($data['cert']) || empty($data['pkey'])) {
            throw new CertificateException(__('The PFX file does not contain both a certificate and its private key.'));
        }
        $key = openssl_pkey_get_private($data['pkey']);
        if ($key === false) {
            throw new CertificateException(__('The private key in the PFX file could not be read.'));
        }

        return $this->check($data['cert'], $key, array_values((array) ($data['extracerts'] ?? [])));
    }

    /** @return list<string> PEM blocks */
    private function pemCertificates(string $text): array
    {
        preg_match_all(self::PEM_CERT, str_replace("\r", '', $text), $m);

        return array_values(array_map(fn ($c) => $this->normalisePem($c), $m[0]));
    }

    private function normalisePem(string $pem): string
    {
        $body = preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);

        return "-----BEGIN CERTIFICATE-----\n".chunk_split((string) $body, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    /** @param list<string> $chain */
    private function check(string $certPem, OpenSSLAsymmetricKey $key, array $chain): ParsedCertificate
    {
        $cert = @openssl_x509_read($certPem);
        if (! $cert instanceof OpenSSLCertificate) {
            throw new CertificateException(__('The certificate could not be read.'));
        }
        if (! openssl_x509_check_private_key($cert, $key)) {
            throw new CertificateException(__('The private key does not belong to this certificate.'));
        }
        $info = openssl_x509_parse($cert) ?: [];
        $notAfter = (int) ($info['validTo_time_t'] ?? 0);
        $notBefore = (int) ($info['validFrom_time_t'] ?? 0);
        if ($notAfter <= time()) {
            throw new CertificateException(__('This certificate expired on :date.', ['date' => date('Y-m-d', $notAfter)]));
        }
        $details = openssl_pkey_get_details($key) ?: [];
        if (($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA && ($details['bits'] ?? 0) < 2048) {
            throw new CertificateException(__('The RSA key is shorter than 2048 bits. Browsers no longer accept it.'));
        }

        $warnings = [];
        if ($notBefore > time()) {
            $warnings[] = __('This certificate is only valid from :date.', ['date' => date('Y-m-d H:i', $notBefore)]);
        }
        $names = $this->names($info);
        $subject = $info['subject'] ?? [];
        $issuer = $info['issuer'] ?? [];
        $selfSigned = $subject === $issuer;
        if ($selfSigned) {
            $warnings[] = __('This certificate is self-signed: browsers show a warning to visitors.');
        }

        // Intermediates: each must be a certificate; the first should have signed the site certificate.
        $chainPems = [];
        foreach ($chain as $c) {
            $x = $c instanceof OpenSSLCertificate ? $c : @openssl_x509_read($c);
            if (! $x instanceof OpenSSLCertificate) {
                throw new CertificateException(__('One of the intermediate certificates could not be read.'));
            }
            openssl_x509_export($x, $pem);
            $chainPems[] = $pem;
        }
        // Drop the root certificate (self-signed CA) and duplicates: browsers have those.
        $chainPems = array_values(array_unique(array_filter($chainPems, function (string $pem) use ($certPem) {
            $p = openssl_x509_parse($pem) ?: [];

            return ($p['subject'] ?? null) !== ($p['issuer'] ?? null) && openssl_x509_fingerprint($pem, 'sha256') !== openssl_x509_fingerprint($certPem, 'sha256');
        })));
        if (! $selfSigned) {
            if ($chainPems === []) {
                $warnings[] = __('No intermediate certificates were added. Most browsers cope, but some apps and older phones need them: add the CA bundle from your certificate supplier.');
            } else {
                $chainPems = $this->ordered($certPem, $chainPems, $warnings);
            }
        }

        openssl_x509_export($cert, $outCert);
        openssl_pkey_export($key, $outKey);

        return new ParsedCertificate(
            certificate: $outCert,
            privateKey: $outKey,
            chain: $chainPems,
            commonName: isset($subject['CN']) ? (string) (is_array($subject['CN']) ? end($subject['CN']) : $subject['CN']) : null,
            names: $names,
            issuer: $this->dn($issuer),
            notBefore: $notBefore,
            notAfter: $notAfter,
            selfSigned: $selfSigned,
            fingerprint: strtoupper(implode(':', str_split((string) openssl_x509_fingerprint($cert, 'sha256'), 2))),
            keyType: match ($details['type'] ?? null) {
                OPENSSL_KEYTYPE_RSA => 'RSA '.($details['bits'] ?? ''),
                OPENSSL_KEYTYPE_EC => 'ECDSA '.($details['ec']['curve_name'] ?? ''),
                default => 'other',
            },
            warnings: $warnings,
        );
    }

    /**
     * Order the intermediates from the one that signed the site certificate
     * upwards; warn when the chain does not connect.
     *
     * @param list<string> $chain
     * @param list<string> $warnings
     * @return list<string>
     */
    private function ordered(string $certPem, array $chain, array &$warnings): array
    {
        $out = [];
        $current = $certPem;
        $left = $chain;
        while ($left !== []) {
            $found = null;
            foreach ($left as $i => $candidate) {
                $pub = openssl_pkey_get_public($candidate);
                if ($pub !== false && openssl_x509_verify($current, $pub) === 1) {
                    $found = $i;
                    break;
                }
            }
            if ($found === null) {
                break;
            }
            $out[] = $current = $left[$found];
            unset($left[$found]);
        }
        if ($out === []) {
            $warnings[] = __('The intermediate certificates do not belong to this certificate. Check that you added the CA bundle of the same supplier.');

            return $chain;
        }
        if ($left !== []) {
            $warnings[] = __('Some intermediate certificates are not part of this chain and were left out.');
        }

        return $out;
    }

    /** @return list<string> DNS names (subjectAltName, else CN) */
    private function names(array $info): array
    {
        $names = [];
        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'DNS:')) {
                $names[] = strtolower(substr($part, 4));
            }
        }
        if ($names === [] && isset($info['subject']['CN'])) {
            $names[] = strtolower((string) (is_array($info['subject']['CN']) ? end($info['subject']['CN']) : $info['subject']['CN']));
        }

        return array_values(array_unique($names));
    }

    private function dn(array $dn): string
    {
        $parts = [];
        foreach (['CN', 'O', 'C'] as $k) {
            if (isset($dn[$k])) {
                $parts[] = $k.'='.(is_array($dn[$k]) ? implode('/', $dn[$k]) : $dn[$k]);
            }
        }

        return implode(', ', $parts);
    }

    /** Does a certificate name (possibly *.example.com) cover a host name? */
    public static function covers(string $pattern, string $host): bool
    {
        $pattern = strtolower($pattern);
        $host = strtolower($host);
        if ($pattern === $host) {
            return true;
        }
        if (str_starts_with($pattern, '*.')) {
            $base = substr($pattern, 2);
            if (! str_ends_with($host, '.'.$base)) {
                return false;
            }

            return ! str_contains(substr($host, 0, -strlen('.'.$base)), '.');
        }

        return false;
    }

    /** @param list<string> $names @return list<string> host names that are not covered */
    public static function uncovered(array $names, array $hosts): array
    {
        return array_values(array_filter($hosts, function (string $host) use ($names) {
            foreach ($names as $n) {
                if (self::covers($n, $host)) {
                    return false;
                }
            }

            return true;
        }));
    }
}
