<?php

namespace App\Services\InstantCerts;

use RuntimeException;

class InstantCertificateException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail, public readonly int $retryAfter = 0)
    {
        parent::__construct("{$reason}: {$detail}");
    }

    /** The reseller is busy or rate-limiting: nothing was used, try again later. */
    public function retryable(): bool
    {
        return in_array($this->reason, ['registration_busy', 'rate_limited', 'upstream_error', 'key_busy'], true)
            || str_starts_with($this->reason, 'http_5');
    }
}
