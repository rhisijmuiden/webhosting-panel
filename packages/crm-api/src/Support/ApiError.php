<?php

declare(strict_types=1);

namespace Rhis\CrmApi\Support;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** An error in the shape of the OpenAPI `Error` schema. */
final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self(404, 'not_found', "{$what} not found");
    }

    public static function conflict(string $message): self
    {
        return new self(409, 'conflict', $message);
    }

    public static function response(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($details) {
            $error['details'] = $details;
        }

        return new JsonResponse(['error' => $error], $status);
    }

    public function render(): JsonResponse
    {
        return self::response($this->status, $this->errorCode, $this->getMessage(), $this->details);
    }
}
