<?php

namespace App\Services\Apple;

use RuntimeException;

/**
 * The developer portal refused an App Group change; `reason` is the script's code
 * (SESSION_EXPIRED when the Apple ID must sign in again).
 */
class AppGroupUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
