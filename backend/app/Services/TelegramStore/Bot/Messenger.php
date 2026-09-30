<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\CompletedOrder;
use App\Services\TelegramStore\TelegramApi;
use App\Services\TelegramStore\TelegramApiException;
use Throwable;

/**
 * Puts screens on the user's screen: navigation edits the message the button
 * belongs to, while news (payment, bonus, expiry) arrives as a new message so
 * Telegram notifies the user.
 */
class Messenger
{
    public function __construct(
        private readonly TelegramApi $api,
        private readonly CustomerScreens $screens,
    ) {}

    /**
     * Shows a screen in place of the pressed message, or as a new message.
     * Returns the id of the message now holding the screen.
     */
    public function show(Context $ctx, Screen $screen): ?int
    {
        if ($ctx->messageId !== null && $ctx->messageHasText) {
            try {
                $this->api->editMessage($ctx->chatId, $ctx->messageId, $screen->text, $screen->keyboard());

                return $ctx->messageId;
            } catch (TelegramApiException $exception) {
                if ($exception->isNotModified()) {
                    return $ctx->messageId;
                }
                if ($exception->isUnreachable()) {
                    throw $exception;
                }
                // Too old or deleted: fall through to a fresh message.
            }
        }

        return $this->api->sendMessage($ctx->chatId, $screen->text, $screen->keyboard())['message_id'] ?? null;
    }

    /**
     * Sends to someone other than the current user; failures are recorded,
     * not thrown, and a blocked bot is noted on the customer.
     */
    public function notify(int $chatId, Screen $screen): bool
    {
        try {
            $this->api->sendMessage($chatId, $screen->text, $screen->keyboard());

            return true;
        } catch (TelegramApiException $exception) {
            if ($exception->isUnreachable()) {
                TelegramStoreCustomer::query()->where('chat_id', $chatId)->update(['blocked_at' => now()]);
            } else {
                report($exception);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return false;
    }

    public function welcome(int $chatId): void
    {
        $banner = (string) config('telegram_store.welcome_banner');
        if ($banner !== '' && is_file($banner)) {
            try {
                $this->api->sendPhoto($chatId, $banner, $this->screens->welcomeText(), ['inline_keyboard' => $this->screens->menuRows()]);

                return;
            } catch (TelegramApiException $exception) {
                if ($exception->isUnreachable()) {
                    throw $exception;
                }
                report($exception);
            }
        }
        $this->api->sendMessage($chatId, $this->screens->welcomeText(), ['inline_keyboard' => $this->screens->menuRows()]);
    }

    /** Tells the buyer (code) and the referrer (bonus) about a completed order. */
    public function orderCompleted(CompletedOrder $completed): void
    {
        $order = $completed->order;
        $this->notify($order->chat_id, $this->screens->paid($order, $completed->code));

        if ($completed->referrer && $completed->referralBonus > 0) {
            $this->notify($completed->referrer->chat_id, $this->screens->referralBonus($completed->referralBonus, $completed->referrer->balance_rub));
        }
    }

    public function orderExpired(TelegramStoreOrder $order): void
    {
        $this->notify($order->chat_id, $this->screens->expired($order));
    }

    public function orderRejected(TelegramStoreOrder $order): void
    {
        $this->notify($order->chat_id, $this->screens->rejected($order));
    }

    public function answer(Context $ctx, ?string $text = null, bool $alert = false): void
    {
        if ($ctx->callbackId === null) {
            if ($text !== null) {
                $this->api->sendMessage($ctx->chatId, e($text));
            }

            return;
        }
        try {
            $this->api->answerCallback($ctx->callbackId, $text, $alert);
        } catch (TelegramApiException) {
            // Callback answers expire after a while; nothing to do.
        }
    }
}
