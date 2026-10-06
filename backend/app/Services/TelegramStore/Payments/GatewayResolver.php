<?php

namespace App\Services\TelegramStore\Payments;

class GatewayResolver
{
    /** Platega once its keys are set; before that, mock mode or the manual payment links. */
    public function current(): PaymentGateway
    {
        return match (true) {
            PlategaClient::configured() => app(PlategaGateway::class),
            self::mock() => new MockGateway,
            default => new ManualLinkGateway,
        };
    }

    /** Simulated payments: only while no real provider is connected. */
    public static function mock(): bool
    {
        return (bool) config('telegram_store.mock_payments') && ! PlategaClient::configured();
    }
}
