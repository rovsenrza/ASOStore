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
        public readonly bool $messageHasPhoto = false,
        public readonly ?string $callbackId = null,
        // Set when the update is a tap in our widget: screens then replace that widget's state.
        public readonly ?int $widgetMessageId = null,
        public readonly ?string $via = null,
    ) {}

    /** The widget state the handler produced (the last screen it showed), if any. */
    public ?array $widgetState = null;

    /** A short notice for the widget (what a callback answer would have said). */
    public ?string $toast = null;

    public function userId(): int
    {
        return $this->customer->telegram_user_id;
    }

    public function isAdmin(): bool
    {
        return app(\App\Services\TelegramStore\StoreSettings::class)->isAdmin($this->userId());
    }
}
