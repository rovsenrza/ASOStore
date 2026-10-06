<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\TelegramStore\Payments\PlategaClient;
use App\Services\TelegramStore\StoreSettings;
use App\Services\TelegramStore\TelegramApi;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * What the website's purchase page sells: the Telegram store's plans at their current
 * prices (admins change them in the bot). With Platega connected (`online_payment`), the
 * page's «Оплатить» opens a payment for the signed-in account (POST /store/checkout);
 * every plan also carries the deep link that opens its order in the bot.
 */
class StoreOfferController extends Controller
{
    public function __invoke(StoreSettings $settings, TelegramApi $telegram): JsonResponse
    {
        $bot = $this->botUsername($telegram);
        $plans = collect($settings->plans())
            ->map(fn (array $plan, string $key) => [
                'id' => $key,
                'months' => $plan['months'],
                'days' => $plan['days'],
                'price' => $plan['price'],
                'monthly_price' => (int) round($plan['price'] / max(1, $plan['months'])),
                'saving_percent' => $settings->savingPercent($key),
                'buy_url' => $bot !== null ? "https://t.me/{$bot}?start=buy_{$key}" : null,
            ])
            ->sortBy('months')
            ->values()
            ->all();

        return ApiResponse::ok([
            'currency' => 'RUB',
            'online_payment' => PlategaClient::configured(),
            'plans' => $plans,
            'referral_percent' => $settings->referralPercent(),
            'telegram' => [
                'bot_username' => $bot,
                'bot_url' => $bot !== null ? "https://t.me/{$bot}" : null,
                'news_url' => config('telegram_store.news_url') ?: null,
                'support_url' => config('telegram_store.support_url') ?: null,
            ],
        ])->setPublic()->setMaxAge(300);
    }

    /** Configured, or learned from Telegram once a day; the page keeps its own link without it. */
    private function botUsername(TelegramApi $telegram): ?string
    {
        if (! config('telegram_store.bot_username') && ! config('telegram_store.token')) {
            return null;
        }
        try {
            $username = $telegram->botUsername();
        } catch (Throwable) {
            return null;
        }

        return preg_match('/^\w{5,32}$/', $username) === 1 ? $username : null;
    }
}
