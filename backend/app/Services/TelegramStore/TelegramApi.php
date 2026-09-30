<?php

namespace App\Services\TelegramStore;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin Bot API client. Every message is sent as HTML, so callers escape
 * user-supplied text with e().
 */
class TelegramApi
{
    private function token(): string
    {
        $token = (string) config('telegram_store.token');
        if ($token === '') {
            throw new RuntimeException('Set TELEGRAM_STORE_BOT_TOKEN in the backend environment.');
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return mixed the "result" field
     *
     * @throws TelegramApiException|ConnectionException
     */
    public function call(string $method, array $params = [], int $timeout = 15): mixed
    {
        $response = Http::timeout($timeout)->asJson()->post($this->url($method), $params);
        $body = $response->json() ?? [];
        if (! ($body['ok'] ?? false)) {
            throw new TelegramApiException($method, (int) ($body['error_code'] ?? $response->status()), (string) ($body['description'] ?? 'no description'));
        }

        return $body['result'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function getUpdates(int $offset, int $timeout = 30): array
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => ['message', 'callback_query'],
        ], $timeout + 10) ?? [];
    }

    /**
     * @param  array<string, mixed>|null  $keyboard
     * @return array<string, mixed> the sent message
     */
    public function sendMessage(int $chatId, string $text, ?array $keyboard = null): array
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $keyboard,
        ], fn ($value) => $value !== null));
    }

    /** @param  array<string, mixed>|null  $keyboard */
    public function editMessage(int $chatId, int $messageId, string $text, ?array $keyboard = null): void
    {
        $this->call('editMessageText', array_filter([
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
            'reply_markup' => $keyboard,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param  array<string, mixed>|null  $keyboard
     * @return array<string, mixed> the sent message
     */
    public function sendPhoto(int $chatId, string $path, string $caption, ?array $keyboard = null): array
    {
        $response = Http::timeout(30)
            ->attach('photo', (string) file_get_contents($path), basename($path))
            ->post($this->url('sendPhoto'), array_filter([
                'chat_id' => $chatId,
                'caption' => $caption,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard ? json_encode($keyboard, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            ], fn ($value) => $value !== null));
        $body = $response->json() ?? [];
        if (! ($body['ok'] ?? false)) {
            throw new TelegramApiException('sendPhoto', (int) ($body['error_code'] ?? $response->status()), (string) ($body['description'] ?? 'no description'));
        }

        return $body['result'];
    }

    /** @param  array<string, mixed>|null  $keyboard */
    public function copyMessage(int $chatId, int $fromChatId, int $messageId, ?array $keyboard = null): void
    {
        $this->call('copyMessage', array_filter([
            'chat_id' => $chatId,
            'from_chat_id' => $fromChatId,
            'message_id' => $messageId,
            'reply_markup' => $keyboard,
        ], fn ($value) => $value !== null));
    }

    public function answerCallback(string $callbackId, ?string $text = null, bool $alert = false): void
    {
        $this->call('answerCallbackQuery', array_filter([
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $alert ?: null,
        ], fn ($value) => $value !== null), 10);
    }

    public function botUsername(): string
    {
        $configured = ltrim((string) config('telegram_store.bot_username'), '@');
        if ($configured !== '') {
            return $configured;
        }

        return Cache::remember('telegram_store.bot_username', now()->addDay(), fn () => (string) ($this->call('getMe')['username'] ?? ''));
    }

    public function setCommands(): void
    {
        $this->call('setMyCommands', ['commands' => [
            ['command' => 'start', 'description' => 'Главное меню'],
            ['command' => 'buy', 'description' => 'Купить подписку'],
            ['command' => 'profile', 'description' => 'Мой профиль и заказы'],
            ['command' => 'invite', 'description' => 'Пригласить друга'],
            ['command' => 'help', 'description' => 'Инструкция и поддержка'],
        ]]);
    }

    private function url(string $method): string
    {
        return "https://api.telegram.org/bot{$this->token()}/{$method}";
    }
}
