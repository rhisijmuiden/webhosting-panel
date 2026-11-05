<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

use InvalidArgumentException;

/**
 * One DNS record, as the panel wants it or as Cloudflare has it.
 *
 * Names are fully qualified, lower case and without a trailing dot.
 * TTL 1 means "automatic" in Cloudflare.
 */
final readonly class DnsRecord
{
    public const string MANAGED_MARKER = 'managed:rhis-panel';

    public const array SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];

    public string $type;
    public string $name;
    public string $content;

    /**
     * @param array{priority?: int, weight?: int, port?: int, target?: string}|null $srv
     */
    public function __construct(
        string $type,
        string $name,
        string $content,
        public int $ttl = 1,
        public ?bool $proxied = null,
        public ?int $priority = null,
        public ?array $srv = null,
        public ?string $id = null,
        public ?string $comment = null,
    ) {
        $type = strtoupper($type);
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported record type {$type}");
        }
        if ($type === 'MX' && $priority === null) {
            throw new InvalidArgumentException('MX records need a priority');
        }
        if ($type === 'SRV' && ($srv === null || !isset($srv['port'], $srv['target']))) {
            throw new InvalidArgumentException('SRV records need port and target');
        }
        $this->type = $type;
        $this->name = self::normaliseName($name);
        $this->content = $type === 'TXT' ? self::unquoteTxt($content) : self::normaliseHost($type, $content);
    }

    public static function normaliseName(string $name): string
    {
        return rtrim(strtolower(trim($name)), '.');
    }

    private static function normaliseHost(string $type, string $content): string
    {
        $content = trim($content);

        return in_array($type, ['CNAME', 'MX'], true) ? rtrim(strtolower($content), '.') : $content;
    }

    /** Cloudflare may return TXT content quoted and split in 255 byte chunks. */
    public static function unquoteTxt(string $content): string
    {
        $content = trim($content);
        if ($content === '' || $content[0] !== '"') {
            return $content;
        }
        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $content, $m) > 0) {
            return stripcslashes(implode('', $m[1]));
        }

        return $content;
    }

    /** TXT content as Cloudflare should store it: quoted chunks of at most 255 bytes. */
    public function txtWireContent(): string
    {
        if (strlen($this->content) <= 255) {
            return $this->content;
        }
        $chunks = array_map(
            static fn (string $c): string => '"' . addcslashes($c, '"\\') . '"',
            str_split($this->content, 255),
        );

        return implode(' ', $chunks);
    }

    public function isManaged(): bool
    {
        return $this->comment !== null && str_starts_with($this->comment, self::MANAGED_MARKER);
    }

    /** Relative name ("@", "www", "_dmarc") for display. */
    public function relativeName(string $zone): string
    {
        $zone = self::normaliseName($zone);
        if ($this->name === $zone) {
            return '@';
        }

        return str_ends_with($this->name, '.' . $zone) ? substr($this->name, 0, -strlen($zone) - 1) : $this->name;
    }

    /** Identity of the value, ignoring TTL/proxy: two records with the same fingerprint carry the same data. */
    public function fingerprint(): string
    {
        $srv = $this->srv === null ? '' : implode(':', [
            $this->srv['priority'] ?? $this->priority ?? 0,
            $this->srv['weight'] ?? 0,
            $this->srv['port'],
            rtrim(strtolower((string) $this->srv['target']), '.'),
        ]);

        return implode('|', [$this->type, $this->name, $this->type === 'SRV' ? $srv : $this->content, $this->type === 'MX' ? (string) $this->priority : '']);
    }

    /** True when the settings that do not change identity (TTL, proxy) are also equal. */
    public function sameSettings(self $other): bool
    {
        return $this->ttl === $other->ttl && (bool) $this->proxied === (bool) $other->proxied;
    }

    public function withManagedComment(?string $note = null): self
    {
        return $this->with(comment: self::MANAGED_MARKER . ($note !== null && $note !== '' ? ' ' . $note : ''));
    }

    public function with(?string $id = null, ?string $comment = null): self
    {
        return new self(
            $this->type, $this->name, $this->content, $this->ttl, $this->proxied, $this->priority,
            $this->srv, $id ?? $this->id, $comment ?? $this->comment,
        );
    }

    /** @param array<mixed> $r */
    public static function fromCloudflare(array $r): self
    {
        $type = strtoupper((string) ($r['type'] ?? ''));
        $data = is_array($r['data'] ?? null) ? $r['data'] : null;
        $srv = null;
        if ($type === 'SRV' && $data !== null) {
            $srv = [
                'priority' => (int) ($data['priority'] ?? $r['priority'] ?? 0),
                'weight' => (int) ($data['weight'] ?? 0),
                'port' => (int) ($data['port'] ?? 0),
                'target' => (string) ($data['target'] ?? ''),
            ];
        }

        return new self(
            type: $type,
            name: (string) ($r['name'] ?? ''),
            content: (string) ($r['content'] ?? ''),
            ttl: (int) ($r['ttl'] ?? 1),
            proxied: isset($r['proxied']) ? (bool) $r['proxied'] : null,
            priority: isset($r['priority']) ? (int) $r['priority'] : null,
            srv: $srv,
            id: isset($r['id']) ? (string) $r['id'] : null,
            comment: isset($r['comment']) && $r['comment'] !== '' ? (string) $r['comment'] : null,
        );
    }

    /** @return array<string, mixed> Payload for POST/PUT/PATCH on the Cloudflare DNS records API. */
    public function toCloudflare(): array
    {
        $payload = ['type' => $this->type, 'name' => $this->name, 'ttl' => $this->ttl];
        if ($this->type === 'SRV') {
            $payload['data'] = [
                'priority' => $this->srv['priority'] ?? $this->priority ?? 0,
                'weight' => $this->srv['weight'] ?? 0,
                'port' => $this->srv['port'],
                'target' => $this->srv['target'],
            ];
        } else {
            $payload['content'] = $this->type === 'TXT' ? $this->txtWireContent() : $this->content;
        }
        if ($this->type === 'MX') {
            $payload['priority'] = $this->priority;
        }
        if (in_array($this->type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = (bool) $this->proxied;
        }
        if ($this->comment !== null) {
            $payload['comment'] = $this->comment;
        }

        return $payload;
    }
}
