<?php

namespace App\Services\TelegramStore\Payments;

class GatewayResolver
{
    public function current(): PaymentGateway
    {
        return config('telegram_store.mock_payments') ? new MockGateway : new ManualLinkGateway;
    }
}
