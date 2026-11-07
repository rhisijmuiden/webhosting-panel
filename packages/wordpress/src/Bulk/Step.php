<?php

declare(strict_types=1);

namespace Rhis\WordPress\Bulk;

/** What happened on one site: done, failed, skipped, or waiting for an agent job. */
final class Step
{
    public const OK = 'succeeded';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** Done, but someone should look at it (for example: a scan found problems). */
    public const ATTENTION = 'attention';

    public const WAIT = 'running';

    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $details = [],
        public readonly ?string $job = null,
    ) {}

    /** $details: a flat list of lines, or label => value pairs, shown under the site. */
    public static function ok(?string $message = null, array $details = []): self
    {
        return new self(self::OK, $message, $details);
    }

    public static function failed(string $message, array $details = []): self
    {
        return new self(self::FAILED, $message, $details);
    }

    public static function attention(string $message, array $details = []): self
    {
        return new self(self::ATTENTION, $message, $details);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, $reason);
    }

    /** The work runs in agent job $job; finish() is called when it has ended. */
    public static function wait(string $job, ?string $message = null): self
    {
        return new self(self::WAIT, $message, [], $job);
    }
}
