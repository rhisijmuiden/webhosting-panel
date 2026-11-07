<?php

declare(strict_types=1);

namespace Rhis\Certificates;

/**
 * Makes a private key and a certificate signing request (CSR) to buy a
 * certificate from a CA. The key stays in the panel (encrypted) until the
 * signed certificate is uploaded.
 */
final class CsrGenerator
{
    public const KEY_TYPES = ['ec' => 'ECDSA P-256', 'rsa2048' => 'RSA 2048', 'rsa4096' => 'RSA 4096'];

    /**
     * @param list<string> $names the first is the common name
     * @param array{organization?: ?string, city?: ?string, state?: ?string, country?: ?string} $subject
     * @return array{csr: string, key: string}
     */
    public function make(array $names, string $keyType = 'ec', array $subject = []): array
    {
        $names = array_values(array_unique(array_map('strtolower', $names)));
        $config = tempnam(sys_get_temp_dir(), 'whp-csr');
        file_put_contents($config, "[req]\ndistinguished_name = dn\nreq_extensions = v3_req\n[dn]\n[v3_req]\n"
            ."subjectAltName = ".implode(',', array_map(fn ($n) => 'DNS:'.$n, $names))."\n");
        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256', 'req_extensions' => 'v3_req'];
            $keyOptions = match ($keyType) {
                'rsa2048' => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048],
                'rsa4096' => ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 4096],
                default => ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'],
            };
            $key = openssl_pkey_new($keyOptions);
            if ($key === false) {
                throw new CertificateException(__('No key could be made: :error', ['error' => (string) openssl_error_string()]));
            }
            $dn = array_filter([
                'commonName' => $names[0],
                'organizationName' => $subject['organization'] ?? null,
                'localityName' => $subject['city'] ?? null,
                'stateOrProvinceName' => $subject['state'] ?? null,
                'countryName' => isset($subject['country']) && $subject['country'] !== '' ? strtoupper($subject['country']) : null,
            ], fn ($v) => $v !== null && $v !== '');
            $csr = openssl_csr_new($dn, $key, $options);
            if ($csr === false || $csr === true) {
                throw new CertificateException(__('No CSR could be made: :error', ['error' => (string) openssl_error_string()]));
            }
            openssl_csr_export($csr, $csrPem);
            openssl_pkey_export($key, $keyPem);

            return ['csr' => $csrPem, 'key' => $keyPem];
        } finally {
            @unlink($config);
        }
    }
}
