<?php

namespace App\Services\TelegramStore\Payments;

use App\Models\TelegramStoreOrder;

/**
 * No money moves. Testers (and admins) complete orders with a button; the
 * order then runs the real completion path so the whole funnel can be tested.
 */
class MockGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'mock';
    }

    public function methods(int $telegramUserId): array
    {
        return self::isTester($telegramUserId) ? Methods::LABELS : [];
    }

    public function checkout(TelegramStoreOrder $order, string $method): Checkout
    {
        return new Checkout(null, simulated: true, needsConfirmation: false);
    }

    public static function isTester(int $telegramUserId): bool
    {
        $ids = array_merge(config('telegram_store.admin_ids', []), config('telegram_store.tester_ids', []));

        return in_array((string) $telegramUserId, $ids, true);
    }
}
