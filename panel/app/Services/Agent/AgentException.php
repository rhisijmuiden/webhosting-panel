<?php

namespace App\Services\Agent;

use RuntimeException;

class AgentException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function unreachable(string $reason): self
    {
        return new self('unreachable', $reason);
    }
}
