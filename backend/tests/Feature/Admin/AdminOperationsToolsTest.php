<?php

use App\Enums\DeviceRegistrationStatus as Status;
use App\Enums\RoleSlug;
use App\Models\ActivationCode;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\Subscription;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\TelegramStorePromoCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

// Overview, customer card, subscription changes, registration actions and the store from the web panel.

beforeEach(function () {
    connectFakeAppleTeam();
    $customer = subscribedCustomer();
    postEnrollment(enrollmentChallenge($customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);
    forgetGuards();
    $this->customer = $customer->fresh();
    $this->device = Device::sole();
    $this->registration = DeviceRegistration::sole();
    $this->admin = userWithRoles(RoleSlug::Admin);
});

function storeOrder(array $attributes = []): TelegramStoreOrder
{
    $customer = TelegramStoreCustomer::query()->firstOrCreate(['telegram_user_id' => 555001], [
        'chat_id' => 555001, 'username' => 'buyer_one', 'first_name' => 'Buyer', 'referral_code' => Str::lower(Str::random(8)),
    ]);

    return TelegramStoreOrder::create($attributes + [
        'public_id' => strtolower((string) Str::ulid()),
        'customer_id' => $customer->id, 'telegram_user_id' => 555001, 'chat_id' => 555001, 'username' => 'buyer_one', 'first_name' => 'Buyer',
        'plan' => 'standard', 'plan_key' => 'month1', 'duration_days' => 30, 'price_rub' => 590, 'discount_rub' => 0, 'balance_used_rub' => 0,
        'amount_due_rub' => 590, 'referral_bonus_rub' => 0, 'status' => TelegramStoreOrder::PAID, 'payment_provider' => 'platega',
        'expires_at' => now()->addHour(), 'paid_at' => now(),
    ]);
}

it('shows the overview with devices, teams, subscriptions and sales', function () {
    storeOrder();

    asStaff(userWithRoles(RoleSlug::Support))->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('data.devices.total', 1)
        ->assertJsonPath('data.devices.by_status.ELIGIBLE', 1)
        ->assertJsonPath('data.waiting.total', 0)
        ->assertJsonPath('data.teams.0.eligible', 1)
        ->assertJsonPath('data.subscriptions.active', 1)
        ->assertJsonPath('data.sales.last_24h.orders', 1)
        ->assertJsonPath('data.sales.last_24h.revenue_rub', 590);
});

it('lists a waiting customer with how long Apple has kept them', function () {
    $this->registration->forceFill(['status' => Status::ApplePending, 'status_reason' => 'APPLE_PROCESSING', 'eligible_at' => null, 'created_at' => now()->subHours(50)])->save();

    asStaff($this->admin)->getJson('/api/v1/admin/overview')
        ->assertJsonPath('data.waiting.total', 1)
        ->assertJsonPath('data.waiting.buckets.1_3d', 1)
        ->assertJsonPath('data.waiting.longest.0.user.email', $this->customer->email);
});

it('shows the customer card with devices, codes, orders and the Telegram buyer', function () {
    $order = storeOrder();
    ActivationCode::query()->create([
        'batch_id' => strtolower((string) Str::ulid()), 'code_hash' => hash('sha256', 'x'.Str::random()), 'code_hint' => 'AB12', 'status' => 'REDEEMED',
        'plan' => 'standard', 'duration_days' => 30, 'note' => 'Telegram Store order '.$order->reference(), 'created_by' => $this->admin->id,
    ])->forceFill(['redeemed_by' => $this->customer->id, 'redeemed_at' => now()])->save();

    asStaff(userWithRoles(RoleSlug::Support))->getJson("/api/v1/admin/users/{$this->customer->public_id}/profile")
        ->assertOk()
        ->assertJsonPath('data.access.active', true)
        ->assertJsonPath('data.access.paid_days', 30)
        ->assertJsonPath('data.devices.0.registrations.0.status', 'ELIGIBLE')
        ->assertJsonPath('data.devices.0.registrations.0.team', 'FAKE000001')
        ->assertJsonPath('data.orders.0.reference', $order->reference())
        ->assertJsonPath('data.telegram.0.username', 'buyer_one');

    // The list finds the account by the buyer's Telegram name and by its number.
    forgetGuards();
    asStaff($this->admin)->getJson('/api/v1/admin/users?q=@buyer_one')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', $this->customer->email);
    $this->getJson('/api/v1/admin/users?q='.$this->customer->id)->assertJsonPath('data.0.email', $this->customer->email);
});

it('adds days, removes days and sets the end of access, with an audit entry', function () {
    $end = Subscription::sole()->ends_at->copy();
    asStaff(userWithRoles(RoleSlug::Support))->postJson("/api/v1/admin/users/{$this->customer->public_id}/subscription", ['mode' => 'add', 'days' => 3, 'reason' => 'x'])
        ->assertForbidden();

    forgetGuards();
    asStaff($this->admin)->postJson("/api/v1/admin/users/{$this->customer->public_id}/subscription", ['mode' => 'add', 'days' => 3, 'reason' => 'Компенсация'])
        ->assertOk();
    expect(Subscription::sole()->ends_at->equalTo($end->copy()->addDays(3)))->toBeTrue();

    $this->postJson("/api/v1/admin/users/{$this->customer->public_id}/subscription", ['mode' => 'add', 'days' => -5, 'reason' => 'Лишние дни'])->assertOk();
    expect(Subscription::sole()->ends_at->equalTo($end->copy()->subDays(2)))->toBeTrue();

    $this->postJson("/api/v1/admin/users/{$this->customer->public_id}/subscription", ['mode' => 'set', 'ends_at' => '2027-01-15T12:00:00Z', 'reason' => 'Точная дата'])->assertOk();
    expect(Subscription::sole()->ends_at->equalTo(Carbon::parse('2027-01-15 12:00:00')))->toBeTrue()
        ->and(AuditLog::where('action', 'subscription.adjusted')->count())->toBe(3);
});

it('starts a new access from now when adding days to one that already ended', function () {
    Subscription::sole()->forceFill(['ends_at' => now()->subDays(10)])->save();

    asStaff($this->admin)->postJson("/api/v1/admin/users/{$this->customer->public_id}/subscription", ['mode' => 'add', 'days' => 7, 'reason' => 'x'])->assertOk();

    expect(Subscription::sole()->ends_at->diffInHours(now()->addDays(7), true))->toBeLessThan(1);
});

it('disables a registration and enables it again without compensating the wait', function () {
    asStaff($this->admin)->postJson("/api/v1/admin/device-registrations/{$this->registration->public_id}/disable", ['reason' => 'test'])
        ->assertOk()->assertJsonPath('data.status', 'DISABLED');
    $endBefore = Subscription::sole()->ends_at->copy();

    $this->postJson("/api/v1/admin/device-registrations/{$this->registration->public_id}/enable", ['reason' => 'moved back'])
        ->assertOk()->assertJsonPath('data.status', 'ELIGIBLE');

    $this->artisan('subscriptions:compensate-delays')->assertSuccessful();
    expect(Subscription::sole()->ends_at->equalTo($endBefore))->toBeTrue();

    $this->postJson("/api/v1/admin/device-registrations/{$this->registration->public_id}/enable", ['reason' => 'again'])->assertStatus(409);
});

it('lists store orders and finds them by Telegram name or reference end', function () {
    $order = storeOrder();
    storeOrder(['status' => TelegramStoreOrder::CANCELLED, 'username' => 'someone_else', 'telegram_user_id' => 1]);

    asStaff(userWithRoles(RoleSlug::Support))->getJson('/api/v1/admin/store/orders?status=PAID')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $order->reference());
    $this->getJson('/api/v1/admin/store/orders?q='.$order->shortReference())->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/admin/store/orders?q=@someone_else')->assertJsonCount(1, 'data');
});

it('creates and pauses promo codes and changes prices', function () {
    asStaff($this->admin)->postJson('/api/v1/admin/store/promo-codes', ['code' => 'blog20', 'type' => 'percent', 'value' => 20, 'max_uses' => 50, 'plan_keys' => ['month3']])
        ->assertCreated()->assertJsonPath('data.code', 'BLOG20')->assertJsonPath('data.label', '−20%');
    $this->postJson('/api/v1/admin/store/promo-codes', ['code' => 'BLOG20', 'type' => 'percent', 'value' => 10])->assertUnprocessable();

    $promo = TelegramStorePromoCode::sole();
    $this->postJson("/api/v1/admin/store/promo-codes/{$promo->id}/toggle")->assertOk()->assertJsonPath('data.active', false);

    $this->putJson('/api/v1/admin/store/settings', ['prices' => ['month1' => 690], 'referral_percent' => 20])
        ->assertOk()->assertJsonPath('data.referral_percent', 20);
    expect(collect($this->getJson('/api/v1/admin/store/settings')->json('data.plans'))->firstWhere('key', 'month1')['price'])->toBe(690);
});

it('changes a bot customer balance and refuses to go below zero', function () {
    $customer = storeOrder()->customer;

    asStaff($this->admin)->postJson("/api/v1/admin/store/customers/{$customer->id}/balance", ['amount' => -10, 'reason' => 'x'])->assertStatus(409);
    $this->getJson('/api/v1/admin/store/customers?q=@buyer_one')->assertJsonCount(1, 'data')->assertJsonPath('data.0.paid_orders', 1);
});
