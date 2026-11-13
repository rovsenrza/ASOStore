<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\DeviceRegistration;
use App\Models\Subscription;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\User;
use App\Services\InstantCerts\InstantCertificates;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\RenewalAccounts;
use App\Services\TelegramStore\StoreSettings;
use App\Services\TelegramStore\TelegramApi;
use App\Services\TelegramStore\TelegramApiException;
use Throwable;

/**
 * The store bot as one interactive HTML widget (Bot API 10.4): a single app (resources/
 * telegram-widgets/app.html) that draws any screen from its state — the storefront (v=s),
 * the admin dashboard (v=d) or a generic screen (v=g) converted from the bot's Screen.
 *
 * A tap sends the callback data of the matching button; the bot runs the usual handler, and
 * the screen it would have shown becomes the new state of the SAME message (edited in place,
 * and returned in the query answer so the widget redraws without waiting). News (payment,
 * expiry, admin alerts) arrives as a new widget message.
 *
 * Telegram limits: the HTML ≤ 4096 bytes zlib-compressed; the state is split over chat_data
 * and public_data, 1024 bytes each; an answer carries ≤ 1024 bytes.
 */
class Widgets
{
    public const PART = 1024;

    public const STATE_LIMIT = 2 * self::PART;

    private const NAV = ['menu', 'profile', 'orders', 'invite', 'help'];

    /** The admin tab bar (the customer one is built into the app). */
    private const ADMIN_NAV = [['📊', 'Панель', 'adm:panel'], ['🧾', 'Проверка', 'adm:reviews'], ['🔎', 'Клиент', 'adm:find'], ['🛍', 'Магазин', 'menu']];

    /**
     * How a new account's iPhone is registered. Shown as a second step with nothing preselected:
     * the two differ a lot, and buyers must not take one by accident.
     */
    private const REGISTRATION = [
        ['ins', '⚡ Мгновенно', 'Приложения сразу после оплаты', 'Нет уведомлений MAX и VK · Apple может отозвать'],
        ['std', '🛡 Через Apple', 'Все функции и уведомления MAX и VK', 'Подключение до 72 часов'],
    ];

    private static ?string $html = null;

    public function __construct(
        private readonly TelegramApi $api,
        private readonly StoreSettings $settings,
        private readonly RenewalAccounts $accounts,
        private readonly InstantCertificates $instantCerts,
        private readonly OrderService $orders,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('telegram_store.widgets', true);
    }

    /**
     * The built app (scripts/build-telegram-widget.mjs: esbuild-minified, icon inlined), read
     * once per process.
     */
    public static function html(): string
    {
        return self::$html ??= trim((string) file_get_contents(resource_path('telegram-widgets/app.min.html')));
    }

    /** Sends a new widget message showing $state; null if Telegram refused it. */
    public function send(int $chatId, array $state): ?int
    {
        [$chat, $public] = self::parts($state);
        try {
            return $this->api->sendWidget($chatId, self::html(), $chat, $public)['message_id'] ?? null;
        } catch (TelegramApiException $exception) {
            if ($exception->isUnreachable()) {
                throw $exception;
            }
            report($exception);

            return null;
        }
    }

    /** Puts $state into an existing widget message. */
    public function update(int $chatId, int $messageId, array $state): void
    {
        [$chat, $public] = self::parts($state);
        try {
            $this->api->editMessageWidgetData($chatId, $messageId, $chat, $public);
        } catch (TelegramApiException $exception) {
            if (! $exception->isNotModified()) {
                report($exception);
            }
        }
    }

    /** What the query answer carries: the whole state when it fits, else "r" (wait for the edit). */
    public static function answer(array $state): string
    {
        $json = self::encode($state);

        return strlen($json) <= self::PART ? $json : 'r';
    }

    /**
     * Any bot screen as a generic widget view: the bold first line becomes the title, the rest
     * keeps bold and code (tap to copy), links turn into buttons, «‹ …» buttons into the back
     * arrow, and the customer (or admin) tab bar sits at the bottom.
     *
     * @return array<string, mixed>
     */
    public function fromScreen(Screen $screen, ?string $via = null, ?string $toast = null): array
    {
        $html = str_replace(["\r", '<br>', '<br/>'], ['', "\n", "\n"], $screen->text);
        $title = null;
        if (preg_match('/^\s*<b>([^<\n]{1,60})<\/b>\s*(\n|$)/u', $html, $m)) {
            $title = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5));
            $html = substr($html, strlen($m[0]));
        }
        $body = preg_replace('/<a\s[^>]*>(.*?)<\/a>/su', '$1', $html);
        $body = preg_replace('/<(?!\/?(b|code)>)[^>]*>/u', '', (string) $body);
        $body = html_entity_decode((string) $body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = trim((string) preg_replace("/\n{3,}/", "\n\n", $body));

        $back = null;
        $admin = false;
        $rows = [];
        foreach ($screen->rows as $row) {
            $out = [];
            foreach ($row as $button) {
                $text = (string) $button['text'];
                if (isset($button['url'])) {
                    $out[] = [$text, $button['url'], 2];

                    continue;
                }
                $data = (string) ($button['callback_data'] ?? '');
                $admin = $admin || str_starts_with($data, 'adm:');
                if (str_starts_with($text, '‹')) {
                    $back ??= $data;

                    continue;
                }
                $out[] = [$text, $data, self::primary($text) ? 1 : 0];
            }
            if ($out !== []) {
                $rows[] = $out;
            }
        }
        // The storefront has no room for the pages the admin added to the menu: they live in Help.
        if ($via === 'help') {
            $custom = [];
            foreach ($this->settings->botBlocks() as $block) {
                if (($block['enabled'] ?? true) && ($block['in_menu'] ?? true)) {
                    $custom[] = [(string) $block['label'], 'cb:'.$block['id'], 0];
                }
            }
            array_unshift($rows, ...array_chunk($custom, 2));
        }
        // A back arrow to the menu is the storefront tab already.
        if ($back === 'menu') {
            $back = null;
        }

        $state = array_filter([
            'v' => 'g',
            't' => $title ?? $this->settings->brand(),
            'x' => $body,
            'b' => $rows,
            'bk' => $back,
            'n' => ($isAdmin = $admin || str_starts_with((string) $via, 'adm:')) ? ($via ?? 'a') : ($via !== null && in_array($via, self::NAV, true) ? $via : 'x'),
            'nv' => $isAdmin ? self::ADMIN_NAV : null,
            'm' => $toast,
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);

        return self::fit($state);
    }

    /**
     * The storefront: status, new iPhone or extension (with the known accounts), the terms,
     * the registration method and a paid code waiting to be used.
     *
     * @return array<string, mixed>
     */
    public function storeState(TelegramStoreCustomer $customer, ?string $mode = null, ?string $toast = null): array
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
                $plan['months'] > 1 ? (int) ceil($plan['price'] / $plan['months']).' ₽/мес' : 'попробовать',
                $key === $best && $saving > 0 ? 1 : 0,
                $saving > 0 ? "−{$saving}%" : null,
            ];
        }

        $accounts = $this->accounts->for($customer)->take(3);
        // Access is paid per iPhone: the customer extends one of their iPhones (all known accounts).
        $devices = \App\Models\Device::query()->whereIn('user_id', $accounts->pluck('id'))
            ->with('activeSubscription')->orderByRaw('udid_hash IS NULL')->orderBy('id')->limit(6)->get();
        $first = $devices->first(fn (\App\Models\Device $device) => $device->activeSubscription !== null) ?? $devices->first();
        $end = $first?->activeSubscription?->ends_at;
        $unused = $this->orders->unusedCodes($customer)->first();

        return self::fit(array_filter([
            'v' => 's',
            't' => $this->settings->brand(),
            's' => match (true) {
                $first === null => null,
                $first->activeSubscription !== null && $end === null => 'бессрочно',
                $end !== null && $end->isFuture() => 'до '.$end->copy()->setTimezone((string) config('telegram_store.display_timezone', 'Europe/Moscow'))->format('d.m').' · '.max(0, (int) ceil(now()->diffInHours($end, false) / 24)).' дн',
                default => 'закончилась',
            },
            'on' => $first?->activeSubscription !== null ? 1 : null,
            'a' => $devices->map(fn (\App\Models\Device $device) => [
                $device->public_id,
                $device->isEnrolled()
                    ? (\App\Services\Devices\CustomerDevices::model($device->product) ?? 'iPhone').' ··'.$device->udid_hint
                    : 'Новый iPhone',
                $this->deviceAccess($device),
            ])->values()->all(),
            // A new iPhone goes onto this account instead of starting another one.
            'ac' => $accounts->first()?->public_id,
            'p' => $p,
            'o' => $this->instantCerts->offered() ? self::REGISTRATION : null,
            'c' => $customer->balance_rub > 0 ? (int) $customer->balance_rub : null,
            'md' => $mode,
            'uc' => $unused ? ['🎟 Оплаченный код ждёт — применить ›', 'use:'.$unused->public_id] : null,
            'lk' => $accounts->isEmpty() ? ['🔗 Мой аккаунт не найден — найти по почте', 'link'] : null,
            'n' => 'menu',
            'm' => $toast,
        ], fn ($value) => $value !== null && $value !== []));
    }

    /**
     * Admin dashboard for 1, 7 or 30 days: revenue up top, then what needs someone.
     *
     * @return array<string, mixed>
     */
    public function adminState(int $days = 1): array
    {
        $days = in_array($days, [1, 7, 30], true) ? $days : 1;
        $since = Format::dayStart($days - 1);
        $paid = TelegramStoreOrder::query()->where('status', TelegramStoreOrder::PAID)->where('paid_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('payment_provider')->orWhere('payment_provider', '!=', 'mock'));
        $count = (clone $paid)->count();
        $reviews = TelegramStoreOrder::query()->where('status', TelegramStoreOrder::REVIEW)->count();
        $active = Subscription::query()->where('status', 'ACTIVE')->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))->distinct()->count('user_id');
        $ending = Subscription::query()->where('status', 'ACTIVE')->whereBetween('ends_at', [now(), now()->addDay()])->distinct()->count('user_id');
        $waiting = DeviceRegistration::query()->whereIn('status', ['ENROLLED', 'APPLE_PENDING', 'QUOTA_BLOCKED', 'NO_ELIGIBLE_TEAM'])->count();
        $failed = DeviceRegistration::query()->where('status', 'APPLE_FAILED')->where('updated_at', '>=', now()->subDay())->count();
        $newUsers = TelegramStoreCustomer::query()->where('created_at', '>=', $since)->count();

        return [
            'v' => 'd',
            't' => 'Админ-панель',
            's' => now((string) config('telegram_store.display_timezone', 'Europe/Moscow'))->format('H:i'),
            'd' => $days,
            'u' => $days === 1 ? 'Выручка сегодня' : "Выручка за {$days} дней",
            'rv' => Format::rub((int) (clone $paid)->sum('amount_due_rub')),
            'pc' => $count.' '.Format::plural($count, 'покупка', 'покупки', 'покупок').' · '.$newUsers.' '.Format::plural($newUsers, 'новый', 'новых', 'новых').' в боте',
            'k' => [
                ['на проверке', (string) $reviews, $reviews > 0 ? 1 : 0, 'adm:reviews'],
                ['ждут Apple', (string) $waiting, $waiting > 0 ? 1 : 0],
                ['кончается за 24 ч', (string) $ending, $ending > 0 ? 1 : 0],
                ['ошибок Apple за сутки', (string) $failed, $failed > 0 ? 1 : 0],
                ['клиентов с доступом', (string) $active, 0],
                ['подробная статистика', '📊', 0, 'adm:stats:'.$days],
            ],
            'b' => [[['💲 Цены', 'adm:prices'], ['🎟 Промо', 'adm:promos'], ['📣 Рассылка', 'adm:bc']]],
            'n' => 'adm:panel',
            'nv' => self::ADMIN_NAV,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function encode(array $state): string
    {
        return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * The state split at a character boundary into chat_data and public_data.
     *
     * @return array{0: string, 1: string}
     */
    public static function parts(array $state): array
    {
        $json = self::encode(self::fit($state));
        $chat = mb_strcut($json, 0, self::PART, 'UTF-8');

        return [$chat, substr($json, strlen($chat))];
    }

    /** Shortens the text (then drops the toast) until the state fits both parts. */
    private static function fit(array $state): array
    {
        while (strlen(self::encode($state)) > self::STATE_LIMIT - 8) {
            if (! empty($state['x']) && mb_strlen($state['x']) > 40) {
                $state['x'] = rtrim(mb_substr(strip_tags($state['x']), 0, (int) (mb_strlen(strip_tags($state['x'])) * 0.85))).'…';
            } elseif (! empty($state['a'])) {
                array_pop($state['a']);
            } elseif (isset($state['m'])) {
                unset($state['m']);
            } elseif (! empty($state['b'])) {
                array_pop($state['b']);
            } else {
                break;
            }
        }

        return $state;
    }

    private static function primary(string $text): bool
    {
        return str_contains($text, '₽') || preg_match('/^(💳|✅|🛍|⚡|🛡|📲|🔑)/u', $text) === 1;
    }

    private function deviceAccess(\App\Models\Device $device): string
    {
        $active = $device->activeSubscription;
        if ($active && $active->ends_at === null) {
            return 'бессрочно';
        }
        $end = $device->accessEndsAt();

        return $end ? ($end->isFuture() ? 'до ' : 'кончилась ').$end->copy()->setTimezone((string) config('telegram_store.display_timezone', 'Europe/Moscow'))->format('d.m') : 'нет подписки';
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
