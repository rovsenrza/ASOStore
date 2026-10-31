<?php

use App\Enums\ActivationCodeStatus;
use App\Enums\RoleSlug;
use App\Models\ActivationCode;
use App\Models\Subscription;
use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\CompletedOrder;
use App\Services\TelegramStore\OrderFulfillment;
use App\Services\TelegramStore\OrderService;

/*
| A paid website order is completed once and its code redeemed on the buyer's account once,
| however many callbacks (or admin confirmations) arrive for it.
*/

beforeEach(function () {
    userWithRoles(RoleSlug::Admin); // issues the activation codes
    $this->messenger = Mockery::mock(Messenger::class);
    app()->instance(Messenger::class, $this->messenger);
    $this->buyer = userWithRoles(RoleSlug::Customer);
    $this->order = app(OrderService::class)->createForUser($this->buyer, 'month1');
});

it('completes a paid order once and turns access on for the buyer once', function () {
    $this->messenger->shouldNotReceive('notifyAdmins');
    $this->messenger->shouldNotReceive('orderCompleted');
    $orders = app(OrderService::class);

    $first = $orders->complete($this->order, 'platega', settled: true, reference: 'tx-1');
    expect($first)->toBeInstanceOf(CompletedOrder::class);
    app(OrderFulfillment::class)->deliver($first);

    // A repeated callback finds the order already paid: nothing more is issued or delivered.
    expect($orders->complete($this->order, 'platega', settled: true, reference: 'tx-1'))->toBeNull()
        ->and($orders->complete($this->order, 'platega', adminTelegramId: 1000))->toBeNull();

    expect($this->order->refresh()->status)->toBe(TelegramStoreOrder::PAID)
        ->and($this->order->payment_reference)->toBe('tx-1')
        ->and($orders->activationCode($this->order))->toBe($first->code)
        ->and(ActivationCode::query()->count())->toBe(1)
        ->and(ActivationCode::sole()->status)->toBe(ActivationCodeStatus::Redeemed)
        ->and(ActivationCode::sole()->redeemed_by)->toBe($this->buyer->id)
        ->and(Subscription::query()->where('user_id', $this->buyer->id)->count())->toBe(1);
});

it('tells the admins instead of failing when the code cannot be redeemed', function () {
    $completed = app(OrderService::class)->complete($this->order, 'platega', settled: true, reference: 'tx-2');
    app(OrderFulfillment::class)->deliver($completed);

    // Delivering the same completion again must not grant a second subscription.
    $this->messenger->shouldReceive('notifyAdmins')->once();
    app(OrderFulfillment::class)->deliver($completed);

    expect(Subscription::query()->where('user_id', $this->buyer->id)->count())->toBe(1);
});
