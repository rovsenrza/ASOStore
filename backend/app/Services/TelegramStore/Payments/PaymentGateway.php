<?php

namespace App\Services\TelegramStore\Payments;

use App\Models\TelegramStoreOrder;

/**
 * A way to take the money for an order. A real provider implements this and
 * calls OrderService::complete() from its webhook once the payment settles.
 */
interface PaymentGateway
{
    /** Short id stored on the order (payment_provider). */
    public function name(): string;

    /**
     * Methods this customer may use, as [method => button label].
     *
     * @return array<string, string>
     */
    public function methods(int $telegramUserId): array;

    public function checkout(TelegramStoreOrder $order, string $method): Checkout;
}
