<?php

namespace App\Services\TelegramStore\Bot;

use App\Services\TelegramStore\TelegramApi;
use App\Services\TelegramStore\TelegramApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Customers must follow the news channel before they can buy (telegram_store.required_channel):
 * news, instructions and catalog updates reach them there. The bot has to be an admin of the
 * channel to see its members. If Telegram cannot answer, the customer is let through rather
 * than losing a sale to an outage; the failure is logged.
 */
class ChannelGate
{
    /** A confirmed subscription is trusted this long, so every button press does not ask Telegram. */
    private const TRUST_MINUTES = 10;

    public function __construct(private readonly TelegramApi $api) {}

    /** The channel's @username, or null when the gate is off. */
    public function channel(): ?string
    {
        $channel = trim((string) config('telegram_store.required_channel'));
        if ($channel === '') {
            return null;
        }

        return '@'.ltrim((string) preg_replace('~^(https?://)?t\.me/~', '', $channel), '@');
    }

    public function channelUrl(): string
    {
        return 'https://t.me/'.ltrim((string) $this->channel(), '@');
    }

    public function passes(int $userId): bool
    {
        $channel = $this->channel();
        if ($channel === null || in_array((string) $userId, config('telegram_store.admin_ids', []), true)) {
            return true;
        }
        if (Cache::has($this->key($userId))) {
            return true;
        }

        try {
            $member = $this->api->call('getChatMember', ['chat_id' => $channel, 'user_id' => $userId]);
        } catch (TelegramApiException $failure) {
            // Telegram says it does not know this person in the channel: not subscribed.
            if ($failure->getCode() === 400 && preg_match('/user not found|participant/i', $failure->getMessage()) === 1) {
                return false;
            }
            Log::warning('telegram_store.channel_gate_unavailable', ['channel' => $channel, 'error' => $failure->getMessage()]);

            return true;
        } catch (ConnectionException $failure) {
            Log::warning('telegram_store.channel_gate_unavailable', ['channel' => $channel, 'error' => $failure->getMessage()]);

            return true;
        }

        $status = is_array($member) ? ($member['status'] ?? null) : null;
        $subscribed = in_array($status, ['creator', 'administrator', 'member'], true)
            || ($status === 'restricted' && ($member['is_member'] ?? false) === true);
        if ($subscribed) {
            Cache::put($this->key($userId), true, now()->addMinutes(self::TRUST_MINUTES));
        }

        return $subscribed;
    }

    /** Asks to subscribe; «Я подписался» checks again and continues with $next (a callback). */
    public function screen(string $next): Screen
    {
        return new Screen(
            "📢 <b>Подпишитесь на наш канал</b>\n\n"
            .'В канале '.e((string) $this->channel()).' — новости каталога, инструкции по установке и важные обновления. '
            ."Там же мы сообщаем, если Apple что-то меняет.\n\n"
            .'Чтобы перейти к оплате, подпишитесь на канал и нажмите «Я подписался».',
            [
                [Screen::link('📢 Подписаться на канал', $this->channelUrl())],
                [Screen::button('✅ Я подписался', 'sub:'.$next)],
                [Screen::button('⬅️ В меню', 'menu')],
            ],
        );
    }

    private function key(int $userId): string
    {
        return 'telegram_store.channel_member.'.$userId;
    }
}
