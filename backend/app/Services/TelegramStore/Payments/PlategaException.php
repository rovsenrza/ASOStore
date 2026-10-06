<?php

namespace App\Services\TelegramStore\Payments;

use RuntimeException;

/**
 * Platega did not answer, refused the request, or answered something unexpected.
 * The code is Platega's HTTP status when it answered with an error.
 */
class PlategaException extends RuntimeException
{
    public function notFound(): bool
    {
        return $this->getCode() === 404;
    }
}
