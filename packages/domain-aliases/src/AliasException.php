<?php

declare(strict_types=1);

namespace Rhis\DomainAliases;

use RuntimeException;
use Throwable;

/** A change that cannot be made; the message is translated and safe to show. */
final class AliasException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
