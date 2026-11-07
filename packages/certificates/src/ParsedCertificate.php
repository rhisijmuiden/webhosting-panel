<?php

declare(strict_types=1);

namespace Rhis\Certificates;

/** An uploaded certificate that passed the checks, ready for the server. */
final readonly class ParsedCertificate
{
    /**
     * @param list<string> $chain intermediate certificates (PEM), from the site certificate upwards
     * @param list<string> $names DNS names the certificate is valid for
     * @param list<string> $warnings problems that do not block the upload
     */
    public function __construct(
        public string $certificate,
        #[\SensitiveParameter] public string $privateKey,
        public array $chain,
        public ?string $commonName,
        public array $names,
        public string $issuer,
        public int $notBefore,
        public int $notAfter,
        public bool $selfSigned,
        public string $fingerprint,
        public string $keyType,
        public array $warnings = [],
    ) {}

    public function chainPem(): ?string
    {
        return $this->chain === [] ? null : implode('', $this->chain);
    }
}
