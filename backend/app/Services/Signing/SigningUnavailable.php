<?php

namespace App\Services\Signing;

use RuntimeException;

/**
 * Signing cannot proceed until an operator acts (no certificate on a runner,
 * Apple not connected, device not registered). Not retried automatically.
 */
class SigningUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
