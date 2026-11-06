<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Mail;

use InvalidArgumentException;

/**
 * Public half of a DKIM key. The private key is created by the agent
 * (mail.dkim_generate) and never leaves /var/lib/rspamd/dkim.
 */
final readonly class DkimKey
{
    public function __construct(
        public string $selector,
        public string $publicKey,
        public string $algorithm = 'rsa',
    ) {
        if (!in_array($algorithm, ['rsa', 'ed25519'], true)) {
            throw new InvalidArgumentException('DKIM algorithm must be rsa or ed25519');
        }
        if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $publicKey)) {
            throw new InvalidArgumentException('DKIM public key must be base64 without headers');
        }
    }

    /** Accepts a PEM public key ("-----BEGIN PUBLIC KEY-----") or a bare base64 key. */
    public static function fromPem(string $selector, string $pem, string $algorithm = 'rsa'): self
    {
        $b64 = preg_replace('/-----[^-]+-----|\s+/', '', $pem) ?? '';

        return new self($selector, $b64, $algorithm);
    }

    public function recordName(string $domain): string
    {
        return $this->selector . '._domainkey.' . strtolower($domain);
    }

    public function txt(): string
    {
        return 'v=DKIM1; k=' . $this->algorithm . '; p=' . $this->publicKey;
    }
}
