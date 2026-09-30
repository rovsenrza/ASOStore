<?php

namespace App\Services\TelegramStore;

use App\Models\TelegramStoreOrder;
use App\Models\TelegramStorePromoCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Promo codes on orders. A code is used once per customer; its limit counts
 * paid orders plus orders still holding it (pending or under review), so a
 * limited code cannot be oversold while checkouts are open.
 */
class PromoService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly BalanceLedger $ledger,
        private readonly StoreSettings $settings,
    ) {}

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function find(string $code): ?TelegramStorePromoCode
    {
        $code = self::normalize($code);

        return preg_match('/^[A-Z0-9_-]{3,32}$/', $code)
            ? TelegramStorePromoCode::query()->where('code', $code)->first()
            : null;
    }

    /**
     * Applies a code to an unpaid order. Balance already moved onto the order
     * goes back to the customer so the discount is taken from the full price.
     * A discount covering the whole price completes the order.
     *
     * @return string|CompletedOrder|null an error for the customer, the completed order, or null when applied
     */
    public function apply(TelegramStoreOrder $order, string $code): string|CompletedOrder|null
    {
        $promo = $this->find($code);
        if (! $promo) {
            return 'Такого промокода нет. Проверьте написание.';
        }

        $error = DB::transaction(function () use ($order, $promo) {
            $locked = TelegramStoreOrder::query()->lockForUpdate()->findOrFail($order->id);
            TelegramStorePromoCode::query()->lockForUpdate()->findOrFail($promo->id);
            if (! $locked->isPayable()) {
                return 'Заказ уже нельзя изменить. Оформите новый.';
            }
            if ($error = $this->problem($promo, $locked)) {
                return $error;
            }
            if ($locked->balance_used_rub > 0 && $locked->customer_id) {
                $this->ledger->change($locked->customer_id, $locked->balance_used_rub, BalanceLedger::ORDER_REFUND, $locked->id, 'promo applied');
            }
            $discount = $promo->discountFor($locked->price_rub);
            $locked->update([
                'promo_code_id' => $promo->id,
                'discount_rub' => $discount,
                'balance_used_rub' => 0,
                'amount_due_rub' => $locked->price_rub - $discount,
            ]);

            return null;
        });
        $order->refresh();

        if ($error !== null) {
            return $error;
        }
        if ($order->amount_due_rub === 0) {
            return $this->orders->complete($order, 'promo');
        }

        return null;
    }

    /** Takes the code off an unpaid order, returning any held balance. */
    public function remove(TelegramStoreOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $locked = TelegramStoreOrder::query()->lockForUpdate()->findOrFail($order->id);
            if (! $locked->isPayable() || $locked->promo_code_id === null) {
                return;
            }
            if ($locked->balance_used_rub > 0 && $locked->customer_id) {
                $this->ledger->change($locked->customer_id, $locked->balance_used_rub, BalanceLedger::ORDER_REFUND, $locked->id, 'promo removed');
            }
            $locked->update(['promo_code_id' => null, 'discount_rub' => 0, 'balance_used_rub' => 0, 'amount_due_rub' => $locked->price_rub]);
        });
        $order->refresh();
    }

    /**
     * Why the code cannot go on this order, or null.
     */
    public function problem(TelegramStorePromoCode $promo, TelegramStoreOrder $order): ?string
    {
        if (! $promo->active || ($promo->expires_at && $promo->expires_at->isPast())) {
            return 'Срок действия промокода истёк.';
        }
        if ($promo->plan_keys && ! in_array($order->plan_key, $promo->plan_keys, true)) {
            $names = collect($promo->plan_keys)
                ->map(fn ($key) => ($plan = $this->settings->plan($key)) ? Bot\Format::months($plan['months']) : null)
                ->filter()->implode(', ');

            return "Промокод действует только для тарифа: {$names}.";
        }
        $holding = fn () => TelegramStoreOrder::query()
            ->where('promo_code_id', $promo->id)
            ->where('id', '!=', $order->id)
            ->where(fn ($q) => $q->whereIn('status', [TelegramStoreOrder::PAID, TelegramStoreOrder::REVIEW])
                ->orWhere(fn ($q) => $q->where('status', TelegramStoreOrder::PENDING)->where('expires_at', '>', now())));
        if ($order->customer_id && $holding()->where('customer_id', $order->customer_id)->exists()) {
            return 'Вы уже использовали этот промокод.';
        }
        if ($promo->max_uses !== null && $holding()->count() >= $promo->max_uses) {
            return 'Лимит активаций промокода исчерпан.';
        }

        return null;
    }

    /**
     * Remembers a code from a "promo_<CODE>" start link; the next order gets it.
     */
    public function remember(int $telegramUserId, TelegramStorePromoCode $promo): void
    {
        Cache::put($this->pendingKey($telegramUserId), $promo->code, now()->addDays(7));
    }

    /** Applies a remembered code to a new order, silently skipping one that no longer fits. */
    public function applyRemembered(TelegramStoreOrder $order): ?CompletedOrder
    {
        $code = Cache::get($this->pendingKey($order->telegram_user_id));
        if (! $code) {
            return null;
        }
        $result = $this->apply($order, $code);
        if (! is_string($result)) {
            Cache::forget($this->pendingKey($order->telegram_user_id));
        }

        return $result instanceof CompletedOrder ? $result : null;
    }

    public function remembered(int $telegramUserId): ?TelegramStorePromoCode
    {
        $code = Cache::get($this->pendingKey($telegramUserId));

        return $code ? $this->find($code) : null;
    }

    /**
     * Parses an admin definition such as
     * "BLOGER20 20% лимит 100 до 31.12.2026 тариф 6,12 заметка Канал Вани".
     *
     * @return array<string, mixed>|string the attributes, or an error
     */
    public function parse(string $definition): array|string
    {
        $tokens = preg_split('/\s+/u', trim($definition)) ?: [];
        $code = self::normalize((string) array_shift($tokens));
        if (! preg_match('/^[A-Z0-9_-]{3,32}$/', $code)) {
            return 'Код: 3–32 символа, латиница, цифры, _ или -.';
        }
        if (TelegramStorePromoCode::query()->where('code', $code)->exists()) {
            return "Промокод {$code} уже существует.";
        }
        $discount = mb_strtolower((string) array_shift($tokens));
        if (preg_match('/^(\d{1,3})%$/', $discount, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 100) {
            $attributes = ['code' => $code, 'type' => TelegramStorePromoCode::PERCENT, 'value' => (int) $m[1]];
        } elseif (preg_match('/^(\d{1,7})(₽|р|руб)$/u', $discount, $m) && (int) $m[1] >= 1) {
            $attributes = ['code' => $code, 'type' => TelegramStorePromoCode::FIXED, 'value' => (int) $m[1]];
        } else {
            return 'Скидка: например 20% или 150₽.';
        }

        while ($tokens !== []) {
            $keyword = mb_strtolower((string) array_shift($tokens));
            if ($keyword === 'заметка') {
                $attributes['note'] = mb_substr(implode(' ', $tokens), 0, 255);
                break;
            }
            $value = (string) array_shift($tokens);
            switch ($keyword) {
                case 'лимит':
                    if (! ctype_digit($value) || (int) $value < 1) {
                        return 'Лимит: целое число больше нуля.';
                    }
                    $attributes['max_uses'] = (int) $value;
                    break;
                case 'до':
                    $zone = (string) config('telegram_store.display_timezone', 'Europe/Moscow');
                    $date = CarbonImmutable::createFromFormat('!d.m.Y', $value, $zone);
                    // TIMESTAMP columns end in January 2038.
                    if (! $date || $date->format('d.m.Y') !== $value || $date->endOfDay()->isPast() || $date->year > 2037) {
                        return 'Дата: ДД.ММ.ГГГГ, не в прошлом и не позже 2037 года.';
                    }
                    $attributes['expires_at'] = $date->endOfDay()->setTimezone((string) config('app.timezone'));
                    break;
                case 'тариф':
                    $byMonths = [];
                    foreach ($this->settings->plans() as $key => $plan) {
                        $byMonths[(string) $plan['months']] = $key;
                    }
                    $keys = [];
                    foreach (explode(',', $value) as $months) {
                        if (! isset($byMonths[$months])) {
                            return 'Тариф: число месяцев через запятую, например 6,12.';
                        }
                        $keys[] = $byMonths[$months];
                    }
                    $attributes['plan_keys'] = array_values(array_unique($keys));
                    break;
                default:
                    return 'Непонятный параметр «'.$keyword.'». Доступны: лимит, до, тариф, заметка.';
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes  from parse()
     */
    public function create(array $attributes, int $adminTelegramId): TelegramStorePromoCode
    {
        return TelegramStorePromoCode::create($attributes + ['created_by' => $adminTelegramId]);
    }

    /**
     * @return array{paid: int, mock: int, holding: int, revenue: int, discount: int}
     */
    public function usage(TelegramStorePromoCode $promo): array
    {
        $paid = $promo->orders()->where('status', TelegramStoreOrder::PAID);
        $real = (clone $paid)->where(fn ($q) => $q->whereNull('payment_provider')->orWhere('payment_provider', '!=', 'mock'));

        return [
            'paid' => (clone $real)->count(),
            'mock' => (clone $paid)->where('payment_provider', 'mock')->count(),
            'holding' => $promo->orders()->where(fn ($q) => $q->where('status', TelegramStoreOrder::REVIEW)
                ->orWhere(fn ($q) => $q->where('status', TelegramStoreOrder::PENDING)->where('expires_at', '>', now())))->count(),
            'revenue' => (int) (clone $real)->sum('amount_due_rub'),
            'discount' => (int) (clone $real)->sum('discount_rub'),
        ];
    }

    private function pendingKey(int $telegramUserId): string
    {
        return 'telegram_store.pending_promo.'.$telegramUserId;
    }
}
