<?php

namespace App\Services\TelegramStore;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin-editable values (prices, referral share, bot greeting/brand/banner) over config defaults.
 */
class StoreSettings
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * Bot greeting override. Blank = fall back to the generated welcome text
     * (CustomerScreens::welcomeText). HTML is allowed (Telegram HTML parse mode).
     */
    public function welcomeText(): ?string
    {
        $value = $this->get('welcome_text');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    public function setWelcomeText(?string $text): void
    {
        $this->set('welcome_text', $text !== null && trim($text) !== '' ? $text : null);
    }

    /** Brand name shown across the bot; config default when unset. */
    public function brand(): string
    {
        $value = $this->get('brand');

        return is_string($value) && trim($value) !== '' ? $value : (string) config('telegram_store.brand', 'Ru App Store');
    }

    public function setBrand(?string $brand): void
    {
        $this->set('brand', $brand !== null && trim($brand) !== '' ? $brand : null);
    }

    /** Banner image path shown as every message's photo; config default when unset. */
    public function welcomeBanner(): ?string
    {
        $value = $this->get('welcome_banner');
        $path = is_string($value) && trim($value) !== '' ? $value : (string) config('telegram_store.welcome_banner');

        return $path !== '' && is_file($path) ? $path : null;
    }

    public function setWelcomeBanner(?string $path): void
    {
        $this->set('welcome_banner', $path !== null && trim($path) !== '' ? $path : null);
        // Messenger caches Telegram's file_id per banner path+size+mtime; a new banner is a new key,
        // so nothing stale is served, but clear any entry for this exact path to be safe.
        if ($path !== null && is_file($path)) {
            Cache::forget('telegram_store.banner_file_id.'.md5($path.'|'.filesize($path).'|'.filemtime($path)));
        }
    }

    /** @return array{welcome_text: ?string, brand: string, welcome_banner: ?string} */
    public function botContent(): array
    {
        return [
            'welcome_text' => $this->welcomeText(),
            'brand' => $this->brand(),
            'welcome_banner' => $this->welcomeBanner(),
        ];
    }

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

    /* ---------- Bot admins (2026-11-08) ---------- */

    /**
     * Telegram ids allowed into the bot's /admin: the ones in .env (cannot be removed here)
     * plus the ones added from the web panel.
     *
     * @return list<string>
     */
    public function adminIds(): array
    {
        $extra = $this->fresh('admin_ids');

        return array_values(array_unique(array_merge(
            array_map('strval', config('telegram_store.admin_ids', [])),
            is_array($extra) ? array_map('strval', $extra) : [],
        )));
    }

    /** @return list<string> */
    public function panelAdminIds(): array
    {
        $extra = $this->fresh('admin_ids');

        return is_array($extra) ? array_values(array_map('strval', $extra)) : [];
    }

    public function isAdmin(int|string $telegramUserId): bool
    {
        return in_array((string) $telegramUserId, $this->adminIds(), true);
    }

    public function firstAdminId(): int
    {
        return (int) ($this->adminIds()[0] ?? 0);
    }

    /** @param list<string> $ids */
    public function setPanelAdminIds(array $ids): void
    {
        $this->set('admin_ids', array_values(array_unique(array_map('strval', $ids))));
    }

    /* ---------- Admin-made bot features (2026-11-08) ---------- */

    /**
     * Pages the admin adds to the bot: a menu button and/or a /command and/or keywords that
     * answer with the page's text (and an optional link button).
     *
     * @return list<array{id: string, label: string, text: string, url: ?string, url_label: ?string, command: ?string, keywords: list<string>, in_menu: bool, enabled: bool}>
     */
    public function botBlocks(): array
    {
        $blocks = $this->get('bot_blocks');

        return is_array($blocks) ? array_values($blocks) : [];
    }

    /** @param list<array<string, mixed>> $blocks */
    public function setBotBlocks(array $blocks): void
    {
        $this->set('bot_blocks', array_values($blocks));
    }

    /** @return array<string, mixed>|null */
    public function botBlock(string $id): ?array
    {
        foreach ($this->botBlocks() as $block) {
            if ($block['id'] === $id && ($block['enabled'] ?? true)) {
                return $block;
            }
        }

        return null;
    }

    /** Standard menu buttons the admin chose to hide (invite, help, news, support, profile). */
    public function hiddenMenuItems(): array
    {
        $hidden = $this->get('hidden_menu_items');

        return is_array($hidden) ? array_values($hidden) : [];
    }

    /** @param list<string> $keys */
    public function setHiddenMenuItems(array $keys): void
    {
        $this->set('hidden_menu_items', array_values(array_unique($keys)));
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

    /** Read past the per-process cache: queue workers live for hours and must see new admins. */
    private function fresh(string $key): mixed
    {
        $value = DB::table('telegram_store_settings')->where('key', $key)->value('value');

        return $value === null ? null : json_decode((string) $value, true);
    }

    private function set(string $key, mixed $value): void
    {
        DB::table('telegram_store_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_at' => now()]);
        $this->cache = null;
    }
}
