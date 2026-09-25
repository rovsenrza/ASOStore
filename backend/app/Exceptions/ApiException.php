<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use RuntimeException;

/**
 * A domain failure that maps to a stable API error code (IMPLEMENTATION_PLAN §5.2).
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly array $details = [],
        public readonly ?int $status = null,
    ) {
        parent::__construct($message ?? $errorCode->message());
    }

    public function httpStatus(): int
    {
        return $this->status ?? $this->errorCode->httpStatus();
    }
}
