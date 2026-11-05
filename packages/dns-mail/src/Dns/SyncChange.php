<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Dns;

final readonly class SyncChange
{
    public const string CREATE = 'create';
    public const string UPDATE = 'update';
    public const string DELETE = 'delete';

    public function __construct(
        public string $action,
        public DnsRecord $record,
        public ?DnsRecord $previous = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(string $zone): array
    {
        return [
            'action' => $this->action,
            'type' => $this->record->type,
            'name' => $this->record->relativeName($zone),
            'content' => $this->record->type === 'SRV' ? self::srvText($this->record) : $this->record->content,
            'priority' => $this->record->priority,
            'previous' => $this->previous === null ? null : ($this->previous->type === 'SRV' ? self::srvText($this->previous) : $this->previous->content),
        ];
    }

    private static function srvText(DnsRecord $r): string
    {
        $s = $r->srv ?? [];

        return sprintf('%d %d %d %s', $s['priority'] ?? 0, $s['weight'] ?? 0, $s['port'] ?? 0, $s['target'] ?? '');
    }
}
