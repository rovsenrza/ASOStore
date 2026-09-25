<?php

namespace App\Services\Apple;

use RuntimeException;

/**
 * A permanent Apple-side failure: retrying the same request will not help.
 */
class AppleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'APPLE_REQUEST_FAILED')
    {
        parent::__construct($message);
    }
}
