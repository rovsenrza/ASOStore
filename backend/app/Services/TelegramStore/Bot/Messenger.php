<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\CompletedOrder;
use App\Services\TelegramStore\TelegramApi;
use App\Services\TelegramStore\TelegramApiException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Puts screens on the user's screen. Every message carries the store banner
 * with the screen as its caption. Navigation edits the message the button
 * belongs to, while news (payment, bonus, expiry) arrives as a new message so
 * Telegram notifies the user.
 */
class Messenger
{
    /** Telegram's caption limit, in UTF-16 code units of the visible text. */
    private const CAPTION_LIMIT = 1024;

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
        $asPhoto = $this->usesBanner($screen);
        // A text message cannot become a photo (or back), so those get a fresh message.
        if ($ctx->messageId !== null && $ctx->messageHasPhoto === $asPhoto) {
            try {
                $asPhoto
                    ? $this->api->editCaption($ctx->chatId, $ctx->messageId, $screen->text, $screen->keyboard())
                    : $this->api->editMessage($ctx->chatId, $ctx->messageId, $screen->text, $screen->keyboard());

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

        return $this->send($ctx->chatId, $screen);
    }

    /** Sends a screen as a new message; returns its id. */
    public function send(int $chatId, Screen $screen): ?int
    {
        if ($this->usesBanner($screen)) {
            try {
                return $this->sendBanner($chatId, $screen);
            } catch (TelegramApiException $exception) {
                if ($exception->isUnreachable()) {
                    throw $exception;
                }
                report($exception);
            }
        }

        return $this->api->sendMessage($chatId, $screen->text, $screen->keyboard())['message_id'] ?? null;
    }

    /**
     * Sends to someone other than the current user; failures are recorded,
     * not thrown, and a blocked bot is noted on the customer.
     */
    public function notify(int $chatId, Screen $screen): bool
    {
        try {
            $this->send($chatId, $screen);

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
        $this->send($chatId, $this->screens->menu());
    }

    /** Sends the screen to every admin of the store. */
    public function notifyAdmins(Screen $screen): void
    {
        foreach (config('telegram_store.admin_ids', []) as $adminId) {
            $this->notify((int) $adminId, $screen);
        }
    }

    /** Tells the buyer (code) and the referrer (bonus) about a completed order. */
    public function orderCompleted(CompletedOrder $completed): void
    {
        $order = $completed->order;
        if ($order->chat_id !== null) {
            $this->notify($order->chat_id, $this->screens->paid($order, $completed->code));
        }

        if ($completed->referrer && $completed->referralBonus > 0) {
            $this->notify($completed->referrer->chat_id, $this->screens->referralBonus($completed->referralBonus, $completed->referrer->balance_rub));
        }
    }

    /** Website orders have no chat; the payment page shows their state. */
    public function orderExpired(TelegramStoreOrder $order): void
    {
        if ($order->chat_id !== null) {
            $this->notify($order->chat_id, $this->screens->expired($order));
        }
    }

    public function orderRejected(TelegramStoreOrder $order): void
    {
        if ($order->chat_id !== null) {
            $this->notify($order->chat_id, $this->screens->rejected($order));
        }
    }

    public function answer(Context $ctx, ?string $text = null, bool $alert = false): void
    {
        if ($ctx->callbackId === null) {
            if ($text !== null) {
                $this->send($ctx->chatId, new Screen(e($text)));
            }

            return;
        }
        try {
            $this->api->answerCallback($ctx->callbackId, $text, $alert);
        } catch (TelegramApiException) {
            // Callback answers expire after a while; nothing to do.
        }
    }

    public static function fitsCaption(string $html): bool
    {
        $visible = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return strlen(mb_convert_encoding($visible, 'UTF-16LE', 'UTF-8')) / 2 <= self::CAPTION_LIMIT;
    }

    private function usesBanner(Screen $screen): bool
    {
        return $this->bannerPath() !== null && self::fitsCaption($screen->text);
    }

    /**
     * Uploads the banner once, then reuses Telegram's file_id. A new banner
     * file (other size or mtime) gets uploaded again.
     */
    private function sendBanner(int $chatId, Screen $screen): ?int
    {
        $path = (string) $this->bannerPath();
        $key = 'telegram_store.banner_file_id.'.md5($path.'|'.filesize($path).'|'.filemtime($path));

        if ($fileId = Cache::get($key)) {
            try {
                return $this->api->sendPhotoById($chatId, $fileId, $screen->text, $screen->keyboard())['message_id'] ?? null;
            } catch (TelegramApiException $exception) {
                if ($exception->isUnreachable()) {
                    throw $exception;
                }
                Cache::forget($key);
            }
        }

        $message = $this->api->sendPhoto($chatId, $path, $screen->text, $screen->keyboard());
        $sizes = $message['photo'] ?? [];
        if ($sizes !== [] && isset($sizes[array_key_last($sizes)]['file_id'])) {
            Cache::forever($key, $sizes[array_key_last($sizes)]['file_id']);
        }

        return $message['message_id'] ?? null;
    }

    private function bannerPath(): ?string
    {
        $path = (string) config('telegram_store.welcome_banner');

        return $path !== '' && is_file($path) ? $path : null;
    }
}
