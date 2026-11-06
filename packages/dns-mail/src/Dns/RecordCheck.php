<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

/**
 * Checks the records a domain needs against what the public DNS says, for
 * domains whose DNS the customer keeps at another provider. Every wanted
 * record gets a result: "ok", "missing" or "wrong" (something else is there).
 */
final class RecordCheck
{
    public const OK = 'ok';
    public const MISSING = 'missing';
    public const WRONG = 'wrong';

    /** Types the check can look up. TLSA/CAA are left out (shown, not checked). */
    public const CHECKED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV'];

    public function __construct(private readonly Resolver $resolver)
    {
    }

    /**
     * @param list<DnsRecord> $wanted
     * @return list<array{record: DnsRecord, status: string, found: list<DnsRecord>}>
     */
    public function check(array $wanted): array
    {
        $cache = [];
        $found = function (string $name, string $type) use (&$cache): array {
            return $cache[$type . ' ' . $name] ??= $this->resolver->lookup($name, $type);
        };

        // Wanted values per name+type, so "an extra A record" can be spotted.
        $wantedBySlot = [];
        foreach ($wanted as $r) {
            $wantedBySlot[$r->type . ' ' . $r->name][] = $r;
        }

        $results = [];
        foreach ($wanted as $r) {
            if (!in_array($r->type, self::CHECKED_TYPES, true)) {
                continue;
            }
            $there = $found($r->name, $r->type);
            if ($r->type === 'TXT') {
                $results[] = $this->txt($r, $there);
                continue;
            }
            $match = array_filter($there, static fn (DnsRecord $f): bool => self::same($r, $f));
            if ($match === []) {
                // A CNAME instead of the wanted A record (or the other way round) is also "wrong", not "missing".
                $other = $there ?: ($r->type === 'CNAME' ? [...$found($r->name, 'A'), ...$found($r->name, 'AAAA')] : $found($r->name, 'CNAME'));
                $results[] = ['record' => $r, 'status' => $other ? self::WRONG : self::MISSING, 'found' => array_values($other)];
                continue;
            }
            // Present, but next to values we do not want (an old web server's IP, a second MX).
            $extra = array_values(array_filter($there, static function (DnsRecord $f) use ($wantedBySlot, $r): bool {
                foreach ($wantedBySlot[$r->type . ' ' . $r->name] as $w) {
                    if (self::same($w, $f)) {
                        return false;
                    }
                }

                return true;
            }));
            $results[] = ['record' => $r, 'status' => $extra ? self::WRONG : self::OK, 'found' => $extra];
        }

        return $results;
    }

    /** @param list<array{status: string}> $results */
    public static function allOk(array $results): bool
    {
        foreach ($results as $res) {
            if ($res['status'] !== self::OK) {
                return false;
            }
        }

        return true;
    }

    /** @param list<DnsRecord> $there */
    private function txt(DnsRecord $r, array $there): array
    {
        foreach ($there as $f) {
            if (self::normaliseTxt($f->content) === self::normaliseTxt($r->content)) {
                return ['record' => $r, 'status' => self::OK, 'found' => []];
            }
        }
        // Same kind of record (SPF, DMARC, DKIM, ...) with other content: needs changing, not adding.
        $kind = self::txtKind($r->content);
        $sameKind = $kind === null ? [] : array_values(array_filter($there, static fn (DnsRecord $f): bool => self::txtKind($f->content) === $kind));

        return ['record' => $r, 'status' => $sameKind ? self::WRONG : self::MISSING, 'found' => $sameKind];
    }

    private static function same(DnsRecord $want, DnsRecord $got): bool
    {
        return match ($want->type) {
            'A', 'AAAA' => inet_pton($want->content) !== false && @inet_pton($got->content) === inet_pton($want->content),
            'MX' => $got->content === $want->content && $got->priority === $want->priority,
            'SRV' => $got->fingerprint() === $want->fingerprint(),
            default => $got->content === $want->content,
        };
    }

    private static function txtKind(string $content): ?string
    {
        return preg_match('/^(v=[a-z0-9]+|ms=)/i', trim($content), $m) ? strtolower($m[1]) : null;
    }

    private static function normaliseTxt(string $content): string
    {
        $c = strtolower(trim($content));
        $c = (string) preg_replace('/\s*;\s*/', ';', $c);
        $c = (string) preg_replace('/\s+/', ' ', $c);

        return rtrim($c, ';');
    }
}
