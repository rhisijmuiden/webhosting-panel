<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Mail;

use InvalidArgumentException;

/**
 * DMARC generator (RFC 7489).
 *
 * Recommended rollout: start with "none" plus a report address, move to
 * "quarantine" when the reports are clean, then "reject".
 */
final readonly class DmarcPolicy
{
    private const array POLICIES = ['none', 'quarantine', 'reject'];

    /**
     * @param list<string> $aggregateReports rua addresses
     * @param list<string> $forensicReports ruf addresses
     */
    public function __construct(
        public string $policy = 'quarantine',
        public ?string $subdomainPolicy = null,
        public int $percentage = 100,
        public array $aggregateReports = [],
        public array $forensicReports = [],
        public bool $strictDkim = false,
        public bool $strictSpf = false,
    ) {
        if (!in_array($policy, self::POLICIES, true) || ($subdomainPolicy !== null && !in_array($subdomainPolicy, self::POLICIES, true))) {
            throw new InvalidArgumentException('DMARC policy must be none, quarantine or reject');
        }
        if ($percentage < 0 || $percentage > 100) {
            throw new InvalidArgumentException('DMARC percentage must be 0-100');
        }
        foreach ([...$aggregateReports, ...$forensicReports] as $address) {
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException("Invalid DMARC report address: {$address}");
            }
        }
    }

    public static function parse(string $txt): self
    {
        $tags = [];
        foreach (explode(';', $txt) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k !== '') {
                $tags[strtolower(trim($k))] = trim($v);
            }
        }
        if (strtolower($tags['v'] ?? '') !== 'dmarc1') {
            throw new InvalidArgumentException('Not a DMARC record');
        }
        $addresses = static fn (string $list): array => array_values(array_filter(array_map(
            static fn (string $u): string => preg_replace('/^mailto:/i', '', preg_replace('/!.*$/', '', trim($u))),
            $list === '' ? [] : explode(',', $list),
        )));

        return new self(
            policy: strtolower($tags['p'] ?? 'none'),
            subdomainPolicy: isset($tags['sp']) ? strtolower($tags['sp']) : null,
            percentage: isset($tags['pct']) ? (int) $tags['pct'] : 100,
            aggregateReports: $addresses($tags['rua'] ?? ''),
            forensicReports: $addresses($tags['ruf'] ?? ''),
            strictDkim: strtolower($tags['adkim'] ?? 'r') === 's',
            strictSpf: strtolower($tags['aspf'] ?? 'r') === 's',
        );
    }

    public function toString(): string
    {
        $tags = ['v=DMARC1', 'p=' . $this->policy];
        if ($this->subdomainPolicy !== null) {
            $tags[] = 'sp=' . $this->subdomainPolicy;
        }
        if ($this->percentage !== 100) {
            $tags[] = 'pct=' . $this->percentage;
        }
        if ($this->aggregateReports !== []) {
            $tags[] = 'rua=' . implode(',', array_map(static fn (string $a): string => 'mailto:' . $a, $this->aggregateReports));
        }
        if ($this->forensicReports !== []) {
            $tags[] = 'ruf=' . implode(',', array_map(static fn (string $a): string => 'mailto:' . $a, $this->forensicReports));
            $tags[] = 'fo=1';
        }
        if ($this->strictDkim) {
            $tags[] = 'adkim=s';
        }
        if ($this->strictSpf) {
            $tags[] = 'aspf=s';
        }

        return implode('; ', $tags);
    }

    /**
     * Report addresses outside the domain need an authorisation record at
     * <domain>._report._dmarc.<report-domain> (RFC 7489 7.1).
     *
     * @return list<string> report domains that need that record
     */
    public function externalReportDomains(string $domain): array
    {
        $domain = strtolower($domain);
        $out = [];
        foreach ([...$this->aggregateReports, ...$this->forensicReports] as $a) {
            $d = strtolower(substr(strrchr($a, '@') ?: '@', 1));
            if ($d !== $domain && !str_ends_with($domain, '.' . $d)) {
                $out[] = $d;
            }
        }

        return array_values(array_unique($out));
    }
}
