<?php

namespace App\Services\Apple;

/**
 * Rate limit (429), Apple outage (5xx) or network failure: try again later.
 */
class AppleRetryableException extends AppleException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 60)
    {
        parent::__construct($message, 'APPLE_UNAVAILABLE');
    }
}
