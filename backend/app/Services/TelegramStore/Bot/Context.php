<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\TelegramStoreCustomer;

/**
 * Who sent the update and which bot message it came from, if any.
 */
final class Context
{
    public function __construct(
        public readonly TelegramStoreCustomer $customer,
        public readonly int $chatId,
        public readonly ?int $messageId = null,
        public readonly bool $messageHasText = false,
        public readonly ?string $callbackId = null,
    ) {}

    public function userId(): int
    {
        return $this->customer->telegram_user_id;
    }

    public function isAdmin(): bool
    {
        return in_array((string) $this->userId(), config('telegram_store.admin_ids', []), true);
    }
}
