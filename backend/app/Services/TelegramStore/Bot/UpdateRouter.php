<?php

namespace App\Services\TelegramStore\Bot;

use App\Services\TelegramStore\CustomerService;
use App\Services\TelegramStore\StoreSettings;
use App\Services\TelegramStore\TelegramApiException;

/**
 * Entry point for one Telegram update: private chats only.
 */
class UpdateRouter
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerHandler $customerHandler,
        private readonly AdminHandler $adminHandler,
        private readonly Messenger $messenger,
        private readonly StoreSettings $settings,
        private readonly CustomerScreens $screens,
    ) {}

    /** @param array<string, mixed> $update */
    public function handle(array $update): void
    {
        // Admins may change settings between updates.
        $this->settings->flush();

        try {
            if (isset($update['message'])) {
                $this->message($update['message']);
            } elseif (isset($update['callback_query'])) {
                $this->callback($update['callback_query']);
            }
        } catch (TelegramApiException $exception) {
            if (! $exception->isUnreachable()) {
                throw $exception;
            }
            // The user blocked the bot mid-conversation; Messenger::notify marks it next time.
        }
    }

    /** @param array<string, mixed> $message */
    private function message(array $message): void
    {
        $chat = $message['chat'] ?? [];
        if (($chat['type'] ?? '') !== 'private' || empty($message['from']['id']) || ($message['from']['is_bot'] ?? false)) {
            return;
        }
        $text = trim((string) ($message['text'] ?? ''));
        $payload = preg_match('/^\/start\s+(\S+)$/', $text, $match) ? $match[1] : null;

        [$customer, $referrer] = $this->customers->sync($message['from'], (int) $chat['id'], $payload);
        if ($referrer && $referrer->id !== $customer->id) {
            $this->messenger->notify($referrer->chat_id, $this->screens->referralJoined($this->settings->referralPercent()));
        }

        $ctx = new Context($customer, (int) $chat['id']);
        if ($ctx->isAdmin() && $this->adminHandler->message($ctx, $message)) {
            return;
        }
        $this->customerHandler->message($ctx, $text);
    }

    /** @param array<string, mixed> $callback */
    private function callback(array $callback): void
    {
        $message = $callback['message'] ?? [];
        $chat = $message['chat'] ?? [];
        if (($chat['type'] ?? '') !== 'private' || empty($callback['from']['id'])) {
            return;
        }
        [$customer] = $this->customers->sync($callback['from'], (int) $chat['id']);
        $ctx = new Context(
            $customer,
            (int) $chat['id'],
            isset($message['message_id']) ? (int) $message['message_id'] : null,
            isset($message['photo']),
            (string) $callback['id'],
        );
        $data = (string) ($callback['data'] ?? '');

        if (str_starts_with($data, 'adm:')) {
            $ctx->isAdmin() ? $this->adminHandler->callback($ctx, $data) : $this->messenger->answer($ctx);

            return;
        }
        $this->customerHandler->callback($ctx, $data);
    }
}
