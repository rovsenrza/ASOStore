<?php

namespace App\Services\Devices;

use App\Models\ActivationCode;
use App\Models\DeviceRegistration;
use App\Models\TelegramStoreOrder;
use App\Models\User;
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
        $prefix = 'Telegram Store order ';
        $orderIds = ActivationCode::query()->where('redeemed_by', $user->id)
            ->where('note', 'like', $prefix.'%')->pluck('note')
            ->map(fn (string $note) => strtolower(substr($note, strlen($prefix))));

        return TelegramStoreOrder::query()
            ->where('status', TelegramStoreOrder::PAID)
            ->whereNotNull('telegram_user_id')
            ->where(fn ($query) => $query->where('renew_user_id', $user->id)->orWhereIn('public_id', $orderIds->all()))
            ->latest('paid_at')
            ->get(['telegram_user_id', 'username', 'first_name'])
            ->unique('telegram_user_id')
            ->map(fn (TelegramStoreOrder $order) => $order->username
                ? '@'.e($order->username)
                : '<a href="tg://user?id='.$order->telegram_user_id.'">'.e($order->first_name ?: (string) $order->telegram_user_id).'</a> (ID '.$order->telegram_user_id.')')
            ->values();
    }
}
