<?php

use App\Models\TelegramStoreOrder;
use App\Models\User;
use App\Services\TelegramStore\PurchaseAnalytics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function paidOrder(array $attrs): TelegramStoreOrder
{
    return TelegramStoreOrder::create(array_merge([
        'public_id' => strtolower((string) Str::ulid()),
        'plan' => 'standard',
        'plan_key' => 'standard',
        'duration_days' => 30,
        'price_rub' => 299,
        'amount_due_rub' => 299,
        'status' => TelegramStoreOrder::PAID,
        'paid_at' => now(),
        'expires_at' => now()->addDay(),
    ], $attrs));
}

it('counts repeat buyers, repeat orders and repeat revenue across bot and web, ignoring mock/pending', function () {
    $web = User::factory()->create();

    // Bot buyer A: two paid orders -> repeat (second order revenue = 599).
    paidOrder(['telegram_user_id' => 111, 'chat_id' => 111, 'amount_due_rub' => 299, 'paid_at' => now()->subDays(3)]);
    paidOrder(['telegram_user_id' => 111, 'chat_id' => 111, 'amount_due_rub' => 599, 'paid_at' => now()->subDay()]);
    // Bot buyer B: one paid order -> first only.
    paidOrder(['telegram_user_id' => 222, 'chat_id' => 222, 'amount_due_rub' => 299]);
    // Web buyer C: two paid orders -> repeat (second = 799).
    paidOrder(['user_id' => $web->id, 'amount_due_rub' => 499, 'paid_at' => now()->subDays(2)]);
    paidOrder(['user_id' => $web->id, 'amount_due_rub' => 799, 'paid_at' => now()]);
    // Excluded: a mock payment and an unpaid order.
    paidOrder(['telegram_user_id' => 333, 'chat_id' => 333, 'payment_provider' => 'mock']);
    paidOrder(['telegram_user_id' => 444, 'chat_id' => 444, 'status' => TelegramStoreOrder::PENDING, 'paid_at' => null]);

    $s = app(PurchaseAnalytics::class)->summary(null);

    expect($s['paid_orders'])->toBe(5)
        ->and($s['unique_buyers'])->toBe(3)
        ->and($s['repeat_buyers'])->toBe(2)
        ->and($s['repeat_buyer_rate'])->toBe(66.7)
        ->and($s['first_orders'])->toBe(3)
        ->and($s['repeat_orders'])->toBe(2)
        ->and($s['revenue_rub'])->toBe(299 + 599 + 299 + 499 + 799)
        ->and($s['repeat_revenue_rub'])->toBe(599 + 799)
        ->and($s['by_channel']['telegram']['unique_buyers'])->toBe(2)
        ->and($s['by_channel']['web']['repeat_buyers'])->toBe(1);
});

it('tags a buyer as repeat only once they already have another paid order', function () {
    $first = paidOrder(['telegram_user_id' => 777, 'chat_id' => 777]);
    expect(app(PurchaseAnalytics::class)->isRepeatBuyer($first))->toBeFalse();

    $second = paidOrder(['telegram_user_id' => 777, 'chat_id' => 777]);
    expect(app(PurchaseAnalytics::class)->isRepeatBuyer($second))->toBeTrue()
        // a different buyer's first order is still not a repeat
        ->and(app(PurchaseAnalytics::class)->isRepeatBuyer(paidOrder(['telegram_user_id' => 888, 'chat_id' => 888])))->toBeFalse();
});

it('windows the summary by days', function () {
    paidOrder(['telegram_user_id' => 11, 'chat_id' => 11, 'paid_at' => now()->subDays(40)]);
    paidOrder(['telegram_user_id' => 22, 'chat_id' => 22, 'paid_at' => now()->subDays(2)]);

    expect(app(PurchaseAnalytics::class)->summary(30)['paid_orders'])->toBe(1)
        ->and(app(PurchaseAnalytics::class)->summary(null)['paid_orders'])->toBe(2);
});
