<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Mail;

use InvalidArgumentException;

/**
 * SPF generator and checker.
 *
 *   (new SpfRecord())->withMx()->withInclude('spf.protection.outlook.com')->withAll('-')->toString()
 *   // "v=spf1 mx include:spf.protection.outlook.com -all"
 *
 * SPF allows at most 10 DNS lookups (RFC 7208 4.6.4); lookups() counts the
 * ones this record itself causes, without resolving includes.
 */
final readonly class SpfRecord
{
    public const int MAX_LOOKUPS = 10;
    private const array QUALIFIERS = ['+', '-', '~', '?'];

    /** @param list<string> $mechanisms */
    public function __construct(
        public array $mechanisms = [],
        public string $all = '~',
    ) {
        if (!in_array($all, self::QUALIFIERS, true)) {
            throw new InvalidArgumentException('SPF "all" qualifier must be one of + - ~ ?');
        }
    }

    public static function parse(string $txt): self
    {
        $parts = preg_split('/\s+/', trim($txt)) ?: [];
        if (strtolower(array_shift($parts) ?? '') !== 'v=spf1') {
            throw new InvalidArgumentException('Not an SPF record');
        }
        $all = '?';
        $mechanisms = [];
        foreach ($parts as $p) {
            if (preg_match('/^([+\-~?]?)all$/i', $p, $m)) {
                $all = $m[1] === '' ? '+' : $m[1];
                continue;
            }
            $mechanisms[] = $p;
        }

        return new self(array_values(array_unique($mechanisms)), $all);
    }

    public function withMx(): self
    {
        return $this->add('mx');
    }

    public function withA(?string $host = null): self
    {
        return $this->add($host === null ? 'a' : 'a:' . self::host($host));
    }

    public function withIp(string $ip): self
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->add('ip4:' . $ip);
        }
        [$addr, $cidr] = array_pad(explode('/', $ip, 2), 2, null);
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && ($cidr === null || ctype_digit($cidr) && (int) $cidr <= 32)) {
            return $this->add('ip4:' . $ip);
        }
        if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && ($cidr === null || ctype_digit($cidr) && (int) $cidr <= 128)) {
            return $this->add('ip6:' . $ip);
        }
        throw new InvalidArgumentException("Invalid IP address or range: {$ip}");
    }

    public function withInclude(string $domain): self
    {
        return $this->add('include:' . self::host($domain));
    }

    public function withAll(string $qualifier): self
    {
        return new self($this->mechanisms, $qualifier);
    }

    /** Merge another record's mechanisms in (for example the customer's own extra senders). */
    public function merge(self $other): self
    {
        $r = $this;
        foreach ($other->mechanisms as $m) {
            $r = $r->add($m);
        }

        return $r;
    }

    public function lookups(): int
    {
        $n = 0;
        foreach ($this->mechanisms as $m) {
            if (preg_match('/^[+\-~?]?(include|a|mx|ptr|exists|redirect)\b/i', $m)) {
                $n++;
            }
        }

        return $n;
    }

    /** @return list<string> translation keys for warnings shown next to the generator */
    public function warnings(): array
    {
        $w = [];
        if ($this->lookups() > self::MAX_LOOKUPS) {
            $w[] = 'dns-mail::dns-mail.spf.too_many_lookups';
        }
        if ($this->all === '+') {
            $w[] = 'dns-mail::dns-mail.spf.pass_all';
        }
        foreach ($this->mechanisms as $m) {
            if (str_contains(strtolower($m), 'ptr')) {
                $w[] = 'dns-mail::dns-mail.spf.ptr';
            }
        }
        if (strlen($this->toString()) > 450) {
            $w[] = 'dns-mail::dns-mail.spf.too_long';
        }

        return array_values(array_unique($w));
    }

    public function toString(): string
    {
        return trim('v=spf1 ' . implode(' ', $this->mechanisms) . ' ' . ($this->all === '+' ? '+all' : $this->all . 'all'));
    }

    private function add(string $mechanism): self
    {
        if (in_array($mechanism, $this->mechanisms, true)) {
            return $this;
        }

        return new self([...$this->mechanisms, $mechanism], $this->all);
    }

    private static function host(string $host): string
    {
        $host = rtrim(strtolower(trim($host)), '.');
        if (!preg_match('/^(?=.{1,253}$)([a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            throw new InvalidArgumentException("Invalid host name: {$host}");
        }

        return $host;
    }
}
