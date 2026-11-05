<?php

declare(strict_types=1);

namespace Rhis\DnsMail\Cloudflare;

use RuntimeException;

final class CloudflareException extends RuntimeException
{
    /** @param list<array{code?: int, message?: string}> $errors */
    public function __construct(string $message, public readonly int $httpStatus = 0, public readonly array $errors = [])
    {
        parent::__construct($message, $httpStatus);
    }

    /** @param array<mixed> $payload */
    public static function fromResponse(int $status, array $payload): self
    {
        $errors = is_array($payload['errors'] ?? null) ? $payload['errors'] : [];
        $messages = array_map(
            static fn ($e): string => is_array($e) ? (($e['code'] ?? '?') . ': ' . ($e['message'] ?? 'unknown')) : (string) $e,
            $errors,
        );

        return new self(
            'Cloudflare API error (HTTP ' . $status . ')' . ($messages ? ': ' . implode('; ', $messages) : ''),
            $status,
            $errors,
        );
    }
}
