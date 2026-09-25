<?php

namespace App\Services\Pipeline;

use RuntimeException;

/**
 * Thrown by pipeline work that must wait for an outside service (e.g. Apple
 * rate limiting). The attempt does not count as a failure.
 */
class RetryLater extends RuntimeException
{
    public function __construct(string $reason, public readonly int $seconds)
    {
        parent::__construct($reason);
    }
}
