<?php

namespace App\Services\TelegramStore;

use Illuminate\Support\Facades\DB;

/**
 * Admin-editable values (prices, referral share) over config defaults.
 */
class StoreSettings
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * @return array<string, array{months: int, days: int, price: int}>
     */
    public function plans(): array
    {
        $plans = config('telegram_store.plans', []);
        foreach ($plans as $key => $plan) {
            $price = $this->get('price.'.$key);
            if (is_int($price) && $price > 0) {
                $plans[$key]['price'] = $price;
            }
        }

        return $plans;
    }

    /** @return array{months: int, days: int, price: int}|null */
    public function plan(string $key): ?array
    {
        return $this->plans()[$key] ?? null;
    }

    public function setPrice(string $planKey, int $price): void
    {
        $this->set('price.'.$planKey, $price);
    }

    public function referralPercent(): int
    {
        $value = $this->get('referral_percent');

        return is_int($value) ? $value : (int) config('telegram_store.referral_percent', 15);
    }

    public function setReferralPercent(int $percent): void
    {
        $this->set('referral_percent', $percent);
    }

    /**
     * Percentage saved against paying monthly, for plan buttons.
     */
    public function savingPercent(string $planKey): int
    {
        $plans = $this->plans();
        $base = collect($plans)->sortBy('months')->first();
        $plan = $plans[$planKey] ?? null;
        if (! $base || ! $plan || $plan['months'] <= $base['months']) {
            return 0;
        }
        $full = $base['price'] / $base['months'] * $plan['months'];

        return max(0, (int) round((1 - $plan['price'] / $full) * 100));
    }

    public function flush(): void
    {
        $this->cache = null;
    }

    private function get(string $key): mixed
    {
        $this->cache ??= DB::table('telegram_store_settings')->pluck('value', 'key')
            ->map(fn ($value) => json_decode((string) $value, true))
            ->all();

        return $this->cache[$key] ?? null;
    }

    private function set(string $key, mixed $value): void
    {
        DB::table('telegram_store_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now()]);
        $this->cache = null;
    }
}
