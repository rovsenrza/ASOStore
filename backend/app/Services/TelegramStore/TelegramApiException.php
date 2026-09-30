<?php

namespace App\Services\TelegramStore;

use RuntimeException;

class TelegramApiException extends RuntimeException
{
    public function __construct(public readonly string $method, int $code, string $description)
    {
        parent::__construct("Telegram {$method} failed ({$code}): {$description}", $code);
    }

    /** The user blocked the bot or deleted their account. */
    public function isUnreachable(): bool
    {
        return $this->getCode() === 403;
    }

    public function isNotModified(): bool
    {
        return str_contains($this->getMessage(), 'message is not modified');
    }
}
