<?php

namespace App\Services\TelegramStore;

use App\Services\Activation\ActivationCodeService;
use App\Services\TelegramStore\Bot\Format;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use Throwable;

/**
 * Hands a paid order to its buyer: a bot order gets its code in the chat, a website
 * order gets the code redeemed on the buyer's account so the access is on at once.
 */
class OrderFulfillment
{
    public function __construct(
        private readonly Messenger $messenger,
        private readonly ActivationCodeService $codes,
    ) {}

    public function deliver(CompletedOrder $completed): void
    {
        $order = $completed->order;
        if (! $order->isWeb()) {
            $this->messenger->orderCompleted($completed);

            return;
        }

        try {
            $user = $order->user ?? throw new \RuntimeException('The account of the order is gone.');
            $this->codes->redeem($user, $completed->code);
        } catch (Throwable $exception) {
            report($exception);
            $this->messenger->notifyAdmins(new Screen(
                "⚠️ <b>Оплата на сайте прошла, но доступ не включился</b>\n\n"
                ."Заказ <code>{$order->reference()}</code> · ".Format::rub($order->amount_due_rub)."\n"
                .'Аккаунт: '.e($order->user?->email ?? 'удалён')."\n\n"
                .'Включите доступ вручную или передайте покупателю код из этого заказа.'
            ));
        }
    }
}
