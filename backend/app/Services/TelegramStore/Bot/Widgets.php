<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\DeviceRegistration;
use App\Models\Subscription;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\User;
use App\Services\InstantCerts\InstantCertificates;
use App\Services\TelegramStore\RenewalAccounts;
use App\Services\TelegramStore\StoreSettings;
use App\Services\TelegramStore\TelegramApi;
use App\Services\TelegramStore\TelegramApiException;
use Throwable;

/**
 * Interactive HTML widgets (Bot API 10.4) for the store chat: the storefront the customer
 * configures and pays from (/start), and the live admin dashboard (/admin).
 *
 * A widget's code is fixed once sent; the bot feeds it state through chat_data (≤1024 bytes of
 * JSON) and the widget's taps come back as widget_query updates carrying the same callback
 * data the inline buttons use, so the purchase flow stays in CustomerHandler. Apps without
 * widget support show the caption and the inline keyboard instead, which is the old menu.
 */
class Widgets
{
    public const STATE_LIMIT = 1024;

    public function __construct(
        private readonly TelegramApi $api,
        private readonly StoreSettings $settings,
        private readonly RenewalAccounts $accounts,
        private readonly InstantCertificates $instant,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('telegram_store.widgets', true);
    }

    public static function html(string $name): string
    {
        return (string) file_get_contents(resource_path("telegram-widgets/{$name}.html"));
    }

    /**
     * Sends a widget; null when widgets are off or Telegram refused it (the caller then sends
     * the plain screen).
     *
     * @param  array<string, mixed>  $state
     */
    public function send(int $chatId, string $name, array $state, Screen $fallback): ?int
    {
        if (! self::enabled()) {
            return null;
        }
        try {
            return $this->api->sendWidget($chatId, self::html($name), self::encode($state), $fallback->text, $fallback->keyboard())['message_id'] ?? null;
        } catch (TelegramApiException $exception) {
            if ($exception->isUnreachable()) {
                throw $exception;
            }
            report($exception);

            return null;
        }
    }

    /** @param array<string, mixed> $state */
    public function update(int $chatId, int $messageId, array $state): void
    {
        try {
            $this->api->editMessageWidgetData($chatId, $messageId, self::encode($state));
        } catch (TelegramApiException $exception) {
            if (! $exception->isNotModified()) {
                report($exception);
            }
        }
    }

    /**
     * The customer's storefront: their accounts (to extend), the terms with prices, whether the
     * instant registration is on sale, the balance, and a status line.
     *
     * @return array<string, mixed>
     */
    public function storeState(TelegramStoreCustomer $customer): array
    {
        $plans = $this->settings->plans();
        $best = collect(array_keys($plans))->sortByDesc(fn ($key) => $this->settings->savingPercent($key))->first();
        $p = [];
        foreach ($plans as $key => $plan) {
            $saving = $this->settings->savingPercent($key);
            $p[] = [
                $key,
                $plan['months'].' мес',
                (int) $plan['price'],
                $plan['months'] > 1 ? (int) ceil($plan['price'] / $plan['months']).' ₽/мес'.($saving > 0 ? " −{$saving}%" : '') : 'попробовать',
                $key === $best && $saving > 0 ? 1 : 0,
            ];
        }

        $accounts = $this->accounts->for($customer)->take(3);
        $a = $accounts->map(fn (User $user) => [$user->public_id, RenewalAccounts::maskEmail($user->email), $this->accessShort($user)])->values()->all();

        $first = $accounts->first();
        $end = $first?->activeSubscription?->ends_at;
        $state = [
            'b' => $this->settings->brand(),
            's' => match (true) {
                $first === null => 'Каталог для iPhone',
                $first->activeSubscription !== null && $end === null => 'Подписка бессрочная',
                $end !== null => 'До '.Format::date($end).' · '.max(0, (int) ceil(now()->diffInHours($end, false) / 24)).' дн',
                default => 'Подписка закончилась',
            },
            'on' => $first?->activeSubscription !== null ? 1 : 0,
            'a' => $a,
            'p' => $p,
            'i' => $this->instant->offered() ? 1 : 0,
            'c' => $customer->balance_rub > 0 ? (int) $customer->balance_rub : 0,
        ];

        // Long emails or many accounts must never push the state over Telegram's limit.
        while (strlen(self::encode($state)) > self::STATE_LIMIT && $state['a'] !== []) {
            array_pop($state['a']);
        }

        return $state;
    }

    /**
     * Admin dashboard for a period (1, 7 or 30 days): sales, revenue, new customers and what
     * waits for someone (payments to check, iPhones Apple has not processed).
     *
     * @return array<string, mixed>
     */
    public function adminState(int $days = 1): array
    {
        $days = in_array($days, [1, 7, 30], true) ? $days : 1;
        $since = Format::dayStart($days - 1);
        $paid = TelegramStoreOrder::query()->where('status', TelegramStoreOrder::PAID)->where('paid_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('payment_provider')->orWhere('payment_provider', '!=', 'mock'));
        $reviews = TelegramStoreOrder::query()->where('status', TelegramStoreOrder::REVIEW)->count();
        $active = Subscription::query()->where('status', 'ACTIVE')->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->distinct()->count('user_id');
        $ending = Subscription::query()->where('status', 'ACTIVE')->whereBetween('ends_at', [now(), now()->addDay()])->distinct()->count('user_id');
        $waiting = DeviceRegistration::query()->whereIn('status', ['ENROLLED', 'APPLE_PENDING', 'QUOTA_BLOCKED', 'NO_ELIGIBLE_TEAM'])->count();
        $failed = DeviceRegistration::query()->where('status', 'APPLE_FAILED')->where('updated_at', '>=', now()->subDay())->count();

        return [
            'd' => $days,
            'u' => $days === 1 ? 'сегодня' : "{$days} дн",
            'r' => $reviews,
            'at' => now((string) config('telegram_store.display_timezone', 'Europe/Moscow'))->format('H:i'),
            'k' => [
                ['покупок', (string) (clone $paid)->count()],
                ['выручка', Format::rub((int) (clone $paid)->sum('amount_due_rub'))],
                ['новых в боте', (string) TelegramStoreCustomer::query()->where('created_at', '>=', $since)->count()],
                ['с доступом', (string) $active],
                ['кончается за 24 ч', (string) $ending, $ending > 0 ? 1 : 0],
                ['ждут Apple', (string) $waiting, $waiting > 0 ? 1 : 0],
                ['ошибок Apple за 24 ч', (string) $failed, $failed > 0 ? 1 : 0],
                ['на проверке', (string) $reviews, $reviews > 0 ? 1 : 0],
            ],
        ];
    }

    /** @param array<string, mixed> $state */
    public static function encode(array $state): string
    {
        return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function accessShort(User $user): string
    {
        try {
            $end = $user->activeSubscription?->ends_at ?? $this->accounts->lastEnd($user);
        } catch (Throwable) {
            $end = null;
        }
        if ($user->activeSubscription && $end === null) {
            return 'бессрочно';
        }

        return $end ? ($end->isFuture() ? 'до ' : 'кончилась ').$end->copy()->setTimezone((string) config('telegram_store.display_timezone', 'Europe/Moscow'))->format('d.m') : 'нет подписки';
    }
}
