<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Mail;

use InvalidArgumentException;

/**
 * MTA-STS (RFC 8461): tells sending servers to only deliver to our MX over
 * verified TLS. The policy is served at
 * https://mta-sts.<domain>/.well-known/mta-sts.txt by webmail/autoconfig.
 */
final readonly class MtaSts
{
    public const array MODES = ['testing', 'enforce', 'none'];

    /** @param list<string> $mxHosts may contain one leading wildcard label, e.g. "*.mail.protection.outlook.com" */
    public function __construct(
        public array $mxHosts,
        public string $mode = 'testing',
        public int $maxAge = 604800,
    ) {
        if (!in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException('MTA-STS mode must be testing, enforce or none');
        }
        if ($mxHosts === [] && $mode !== 'none') {
            throw new InvalidArgumentException('MTA-STS needs at least one MX host');
        }
        if ($maxAge < 86400 || $maxAge > 31557600) {
            throw new InvalidArgumentException('MTA-STS max_age must be between 1 day and 1 year');
        }
    }

    public function policy(): string
    {
        $lines = ['version: STSv1', 'mode: ' . $this->mode];
        foreach ($this->mxHosts as $mx) {
            $lines[] = 'mx: ' . strtolower(rtrim($mx, '.'));
        }
        $lines[] = 'max_age: ' . $this->maxAge;

        return implode("\r\n", $lines) . "\r\n";
    }

    /** Policy id changes whenever the policy does, so senders refetch it. */
    public function id(): string
    {
        return substr(hash('sha256', $this->policy()), 0, 20);
    }

    public function txt(): string
    {
        return 'v=STSv1; id=' . $this->id();
    }

    public static function tlsRptTxt(string $reportAddress): string
    {
        if (!filter_var($reportAddress, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid TLS-RPT report address');
        }

        return 'v=TLSRPTv1; rua=mailto:' . $reportAddress;
    }
}
