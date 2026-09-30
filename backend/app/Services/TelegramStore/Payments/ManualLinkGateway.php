<?php

namespace App\Services\TelegramStore\Payments;

use App\Models\TelegramStoreOrder;

/**
 * Configured payment links; an admin checks the payment and confirms it in the bot.
 */
class ManualLinkGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    public function methods(int $telegramUserId): array
    {
        $urls = array_filter(config('telegram_store.payments', []));

        return array_intersect_key(Methods::LABELS, $urls);
    }

    public function checkout(TelegramStoreOrder $order, string $method): Checkout
    {
        $url = (string) config('telegram_store.payments.'.$method);
        $separator = str_contains($url, '?') ? '&' : '?';

        return new Checkout($url.$separator.http_build_query(['order' => $order->reference(), 'amount' => $order->amount_due_rub]));
    }
}
