<?php

namespace App\Services\TelegramStore\Payments;

use App\Models\TelegramStoreOrder;

/**
 * Card / SBP payment on Platega's page. The order completes by itself once
 * Platega confirms the payment (callback, «check payment», reconcile sweep).
 */
class PlategaGateway implements PaymentGateway
{
    public function __construct(private readonly PlategaPayments $payments) {}

    public function name(): string
    {
        return PlategaPayments::PROVIDER;
    }

    public function methods(int $telegramUserId): array
    {
        return [PlategaPayments::METHOD => '💳 Картой или через СБП'];
    }

    public function checkout(TelegramStoreOrder $order, string $method): Checkout
    {
        return new Checkout($this->payments->paymentUrl($order), needsConfirmation: false);
    }
}
