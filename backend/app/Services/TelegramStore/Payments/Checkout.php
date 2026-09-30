<?php

namespace App\Services\TelegramStore\Payments;

final class Checkout
{
    /**
     * @param  string|null  $url  external payment page; null when the payment is simulated
     * @param  bool  $simulated  mock mode: the bot offers a "simulate payment" button
     * @param  bool  $needsConfirmation  the customer reports payment and an admin verifies it
     */
    public function __construct(
        public readonly ?string $url,
        public readonly bool $simulated = false,
        public readonly bool $needsConfirmation = true,
    ) {}
}
