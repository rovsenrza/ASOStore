<?php

namespace App\Services\Devices;

use App\Models\DeviceRegistration;
use App\Models\User;
use App\Services\TelegramStore\AccountContacts;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Tells the store admins in Telegram that Apple enabled a device after a long wait, so they can
 * reach the customer. A customer who bought in the bot is shown with their Telegram contact.
 */
class LongWaitAlert
{
    public function __construct(private readonly Messenger $messenger) {}

    public function handle(DeviceRegistration $registration): void
    {
        $days = (float) config('storefront.long_wait_alert_days', 4);
        $waitedMinutes = (int) $registration->created_at?->diffInMinutes(now(), true);
        if ($days <= 0 || $waitedMinutes < $days * 1440) {
            return;
        }

        try {
            $this->messenger->notifyAdmins(new Screen($this->text($registration, $waitedMinutes)));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function text(DeviceRegistration $registration, int $waitedMinutes): string
    {
        $device = $registration->device;
        $user = $device?->user;
        $lines = [
            '✅ <b>Аккаунт открыт</b> — Apple включила устройство',
            '',
            'Пользователь: '.($user ? e($user->name ?: '—').' · '.e($user->email).' · ID '.$user->id : 'удалён'),
            'Устройство: '.e($device?->product ?: $device?->name ?: '?').' · команда '.e($registration->team?->apple_team_id ?? '?'),
            sprintf('Ожидание: %.1f дн. (с %s UTC)', $waitedMinutes / 1440, $registration->created_at?->format('d.m H:i')),
        ];

        $contacts = $user ? $this->telegramContacts($user) : collect();
        $lines[] = $contacts->isEmpty()
            ? 'Telegram: не найден (покупка не через бота)'
            : 'Telegram: '.$contacts->implode(', ');

        return implode("\n", $lines);
    }

    /**
     * The bot buyers behind this account: whoever bought a code redeemed on it, or renewed it.
     *
     * @return Collection<int, string>
     */
    private function telegramContacts(User $user): Collection
    {
        return app(AccountContacts::class)->telegram($user)
            ->map(fn (array $contact) => $contact['username']
                ? '@'.e($contact['username'])
                : '<a href="tg://user?id='.$contact['telegram_user_id'].'">'.e($contact['first_name'] ?: (string) $contact['telegram_user_id']).'</a> (ID '.$contact['telegram_user_id'].')')
            ->values();
    }
}
