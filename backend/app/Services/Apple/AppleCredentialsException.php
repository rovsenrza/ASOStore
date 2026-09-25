<?php

namespace App\Services\Apple;

/**
 * Apple rejected the API key (revoked, wrong role, wrong issuer).
 */
class AppleCredentialsException extends AppleException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'APPLE_CREDENTIALS');
    }
}
