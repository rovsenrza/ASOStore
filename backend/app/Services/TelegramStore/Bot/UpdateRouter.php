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
            } elseif (isset($update['widget_query'])) {
                $this->widget($update['widget_query']);
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

    /**
     * A tap in our widget. Its data is the callback data of the matching button, run through the
     * usual handlers; whatever screen they show becomes the widget's new state, written into the
     * same message and returned in the answer so the widget redraws at once. The storefront and
     * the admin dashboard have their own views.
     *
     * @param  array<string, mixed>  $query
     */
    private function widget(array $query): void
    {
        $chat = $query['chat'] ?? [];
        $data = (string) ($query['data'] ?? '');
        if (empty($query['id']) || empty($query['from']['id']) || ($chat['type'] ?? '') !== 'private' || empty($query['message_id'])) {
            return;
        }
        [$customer] = $this->customers->sync($query['from'], (int) $chat['id']);
        $ctx = new Context($customer, (int) $chat['id'], widgetMessageId: (int) $query['message_id'], via: $data);
        $widgets = app(Widgets::class);

        try {
            match (true) {
                in_array($data, ['menu', 'buy', 'buy2', 'buynew', 'renew', 'renew2'], true) => $ctx->widgetState = $widgets->storeState(
                    $customer, str_starts_with($data, 'renew') ? 'r' : ($data === 'buynew' ? 'n' : null)),
                $data === 'adm:panel' || str_starts_with($data, 'w:a:') => $ctx->isAdmin()
                    ? $ctx->widgetState = $widgets->adminState($data === 'adm:panel' ? 1 : (int) substr($data, 4))
                    : null,
                str_starts_with($data, 'adm:') => $ctx->isAdmin() ? $this->adminHandler->callback($ctx, $data) : null,
                default => $this->customerHandler->callback($ctx, $data),
            };
        } finally {
            $state = $ctx->widgetState;
            if ($state !== null && $ctx->toast !== null && ! isset($state['m'])) {
                $state['m'] = $ctx->toast;
            }
            if ($state !== null) {
                $widgets->update($ctx->chatId, $ctx->widgetMessageId, $state);
            }
            try {
                $this->api()->answerWidgetQuery((string) $query['id'], $state !== null
                    ? Widgets::answer($state)
                    : ($ctx->toast !== null ? Widgets::encode(['m' => $ctx->toast]) : null));
            } catch (TelegramApiException $exception) {
                report($exception);
            }
        }
    }

    private function api(): \App\Services\TelegramStore\TelegramApi
    {
        return app(\App\Services\TelegramStore\TelegramApi::class);
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
