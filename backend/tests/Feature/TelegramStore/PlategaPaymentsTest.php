<?php

use App\Enums\ActivationCodeStatus;
use App\Enums\RoleSlug;
use App\Models\ActivationCode;
use App\Models\TelegramStoreOrder;
use App\Models\User;
use App\Services\TelegramStore\Bot\UpdateRouter;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\Payments\PlategaPayments;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\OpenApiContract;

const PLATEGA_ADMIN = 1000;
const PLATEGA_BUYER = 3000;

beforeEach(function () {
    config([
        'services.platega.merchant_id' => 'f1e2d3c4-0000-4000-8000-000000000001',
        'services.platega.secret' => 'platega-secret',
        'services.platega.base_url' => 'https://app.platega.io',
        'services.platega.payment_method' => null,
        'telegram_store.token' => 'test-token',
        'telegram_store.bot_username' => 'RuAppStoreBot',
        'telegram_store.admin_ids' => [(string) PLATEGA_ADMIN],
        'telegram_store.tester_ids' => [],
        // Mock mode stays on in production until launch; a connected Platega takes over.
        'telegram_store.mock_payments' => true,
        'telegram_store.welcome_banner' => '',
        'telegram_store.required_channel' => null,
    ]);
    userWithRoles(RoleSlug::Admin);

    // Platega as the test sees it: transactions by id; a test moves them to CONFIRMED etc.
    $this->transactions = [];
    // Set to an HTTP status to make Platega's API fail.
    $this->plategaDown = null;
    Http::fake(function (Request $request) {
        if ($this->plategaDown !== null && str_starts_with($request->url(), 'https://app.platega.io/')) {
            return Http::response(['message' => 'unavailable'], $this->plategaDown);
        }
        if ($request->url() === 'https://app.platega.io/v2/transaction/process') {
            $id = sprintf('3fa85f64-5717-4562-b3fc-%012d', count($this->transactions) + 1);
            $this->transactions[$id] = ['status' => 'PENDING', 'amount' => $request['paymentDetails']['amount'], 'currency' => 'RUB', 'payload' => $request['payload']];

            return Http::response(['transactionId' => $id, 'status' => 'PENDING', 'url' => "https://pay.platega.io/?id={$id}", 'expiresIn' => '00:15:00', 'rate' => 92.5]);
        }
        if (preg_match('#^https://app\.platega\.io/transaction/([0-9a-f-]+)$#', $request->url(), $match)) {
            $tx = $this->transactions[$match[1]] ?? null;

            return $tx === null ? Http::response(['message' => 'not found'], 404) : Http::response([
                'id' => $match[1],
                'status' => $tx['status'],
                'paymentDetails' => ['amount' => $tx['amount'], 'currency' => $tx['currency']],
                'paymentMethod' => 'SBPQR',
                'payload' => $tx['payload'],
            ]);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 77, 'username' => 'RuAppStoreBot']]);
    });

    $this->pay = function (string $id, string $status = 'CONFIRMED', ?float $amount = null) {
        $this->transactions[$id]['status'] = $status;
        if ($amount !== null) {
            $this->transactions[$id]['amount'] = $amount;
        }
    };
    $this->callback = fn (string $id, string $secret = 'platega-secret') => $this->withHeaders([
        'X-MerchantId' => 'f1e2d3c4-0000-4000-8000-000000000001',
        'X-Secret' => $secret,
    ])->postJson('/api/v1/payments/platega/callback', ['id' => $id, 'amount' => 1770, 'currency' => 'RUB', 'status' => 'CONFIRMED', 'paymentMethod' => 2]);
    $this->checkout = fn (User $user, string $plan = 'month6') => asBrowser()->actingAs($user, 'web')
        ->postJson('/api/v1/store/checkout', ['plan' => $plan]);
    $this->sentTo = fn (int $chatId) => collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), 'api.telegram.org') && ($request['chat_id'] ?? null) === $chatId && isset($request['text']))
        ->map(fn (Request $request) => $request['text'])
        ->values();
});

describe('website checkout', function () {
    it('opens a Platega payment for the signed-in account and sends the payer back to the result page', function () {
        $user = userWithRoles(RoleSlug::Customer);

        $response = ($this->checkout)($user)->assertCreated();

        $order = TelegramStoreOrder::sole();
        $response->assertJsonPath('data.payment_url', "https://pay.platega.io/?id={$order->payment_reference}")
            ->assertJsonPath('data.order.id', $order->public_id)
            ->assertJsonPath('data.order.status', 'PENDING')
            ->assertJsonPath('data.order.channel', 'web')
            ->assertJsonPath('data.order.amount', 1770);
        expect(OpenApiContract::errors($response->getContent(), 'StoreCheckoutResponse'))->toBe([])
            ->and($order->user_id)->toBe($user->id)
            ->and($order->chat_id)->toBeNull()
            ->and($order->payment_provider)->toBe('platega');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://app.platega.io/v2/transaction/process'
            && $request->header('X-MerchantId') === ['f1e2d3c4-0000-4000-8000-000000000001']
            && $request->header('X-Secret') === ['platega-secret']
            && $request['paymentDetails'] === ['amount' => 1770, 'currency' => 'RUB']
            && $request['return'] === "http://localhost/payment.html?order={$order->public_id}"
            && $request['failedUrl'] === "http://localhost/payment.html?order={$order->public_id}&failed=1"
            && $request['payload'] === $order->public_id
            && $request['metadata']['userId'] === 'web-'.$user->public_id
            && str_contains($request['description'], '180 дней'));
    });

    it('needs a signed-in account, a known plan and a connected Platega', function () {
        $this->postJson('/api/v1/store/checkout', ['plan' => 'month6'])->assertUnauthorized();

        $user = userWithRoles(RoleSlug::Customer);
        ($this->checkout)($user, 'month99')->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');

        config(['services.platega.secret' => null]);
        ($this->checkout)($user)->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        expect(TelegramStoreOrder::count())->toBe(0);
    });

    it('cancels the order when Platega does not answer', function () {
        $this->plategaDown = 500;

        ($this->checkout)(userWithRoles(RoleSlug::Customer))->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
        expect(TelegramStoreOrder::sole()->status)->toBe(TelegramStoreOrder::CANCELLED);
    });

    it('turns the access on for the account once Platega confirms the payment, once', function () {
        $user = userWithRoles(RoleSlug::Customer);
        ($this->checkout)($user);
        $order = TelegramStoreOrder::sole();
        ($this->pay)($order->payment_reference);

        ($this->callback)($order->payment_reference)->assertOk();
        ($this->callback)($order->payment_reference)->assertOk();

        $order->refresh();
        $subscription = $user->activeSubscription()->sole();
        expect($order->status)->toBe(TelegramStoreOrder::PAID)
            ->and($order->payment_provider)->toBe('platega')
            ->and($subscription->ends_at->diffInDays(now()->addDays(180), true))->toBeLessThan(1)
            ->and(ActivationCode::sole()->status)->toBe(ActivationCodeStatus::Redeemed)
            ->and($user->subscriptions()->count())->toBe(1);
    });

    it('trusts only Platega itself: wrong credentials are refused, and the body never settles an order', function () {
        ($this->checkout)(userWithRoles(RoleSlug::Customer));
        $order = TelegramStoreOrder::sole();

        ($this->callback)($order->payment_reference, 'guessed')->assertUnauthorized();
        // The body says CONFIRMED, Platega's API still says PENDING.
        ($this->callback)($order->payment_reference)->assertOk();

        expect($order->refresh()->status)->toBe(TelegramStoreOrder::PENDING);
    });

    it('answers with an error when Platega cannot be asked, so the callback is retried', function () {
        ($this->checkout)(userWithRoles(RoleSlug::Customer));
        $order = TelegramStoreOrder::sole();
        $this->plategaDown = 502;

        ($this->callback)($order->payment_reference)->assertStatus(503);
    });

    it('does not complete a payment smaller than the order and tells the admins', function () {
        ($this->checkout)(userWithRoles(RoleSlug::Customer));
        $order = TelegramStoreOrder::sole();
        ($this->pay)($order->payment_reference, amount: 100);

        ($this->callback)($order->payment_reference)->assertOk();
        ($this->callback)($order->payment_reference)->assertOk();

        expect($order->refresh()->status)->toBe(TelegramStoreOrder::PENDING)
            ->and(($this->sentTo)(PLATEGA_ADMIN)->filter(fn ($text) => str_contains($text, 'Сумма оплаты не совпала')))->toHaveCount(1);
    });

    it('keeps a live payment page for the same amount and opens a new one after it expires', function () {
        $user = userWithRoles(RoleSlug::Customer);
        ($this->checkout)($user);
        $order = TelegramStoreOrder::sole();
        $payments = app(PlategaPayments::class);

        $first = $order->payment_reference;

        expect($payments->paymentUrl($order))->toBe("https://pay.platega.io/?id={$first}");
        $this->travel(15)->minutes();
        expect($payments->paymentUrl($order->refresh()))->not->toContain($first)
            ->and($this->transactions)->toHaveCount(2);
    });
});

describe('late and repeated payments', function () {
    it('completes an order whose payment arrives after it expired, through the reconcile sweep', function () {
        $user = userWithRoles(RoleSlug::Customer);
        ($this->checkout)($user);
        $order = TelegramStoreOrder::sole();
        $this->travel(31)->minutes();
        app(OrderService::class)->expireStale();
        expect($order->refresh()->status)->toBe(TelegramStoreOrder::EXPIRED);

        ($this->pay)($order->payment_reference);
        expect(app(PlategaPayments::class)->reconcile())->toBe(1);

        expect($order->refresh()->status)->toBe(TelegramStoreOrder::PAID)
            ->and($user->activeSubscription()->exists())->toBeTrue();
    });

    it('tells the admins about a second payment for an order that is already paid', function () {
        ($this->checkout)(userWithRoles(RoleSlug::Customer));
        $order = TelegramStoreOrder::sole();
        ($this->pay)($order->payment_reference);
        ($this->callback)($order->payment_reference);

        // Another page for the same order, paid as well; found through its payload.
        $second = '3fa85f64-5717-4562-b3fc-000000000099';
        $this->transactions[$second] = ['status' => 'CONFIRMED', 'amount' => 1770, 'currency' => 'RUB', 'payload' => $order->public_id];
        ($this->callback)($second)->assertOk();

        expect(ActivationCode::count())->toBe(1)
            ->and(($this->sentTo)(PLATEGA_ADMIN)->filter(fn ($text) => str_contains($text, 'Повторная оплата')))->toHaveCount(1);
    });
});

describe('payment result page', function () {
    it('shows the order without a session and asks Platega when the callback is late', function () {
        ($this->checkout)(userWithRoles(RoleSlug::Customer));
        $order = TelegramStoreOrder::sole();
        forgetGuards();

        $this->getJson("/api/v1/store/orders/{$order->public_id}")->assertOk()->assertJsonPath('data.order.status', 'PENDING');

        ($this->pay)($order->payment_reference);
        $this->travel(6)->seconds();
        $response = $this->getJson("/api/v1/store/orders/{$order->public_id}")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'PAID')
            ->assertJsonPath('data.order.channel', 'web')
            ->assertJsonPath('data.order.bot_url', null);
        expect(OpenApiContract::errors($response->getContent(), 'StoreOrderResponse'))->toBe([]);

        $this->getJson('/api/v1/store/orders/01aaaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound();
    });
});

describe('bot', function () {
    beforeEach(function () {
        $this->press = fn (int $userId, string $data) => app(UpdateRouter::class)->handle([
            'update_id' => 2,
            'callback_query' => [
                'id' => 'cb', 'data' => $data, 'from' => ['id' => $userId, 'first_name' => 'U'.$userId, 'username' => 'buyer'],
                'message' => ['message_id' => 50, 'chat' => ['id' => $userId, 'type' => 'private'], 'text' => 'screen'],
            ],
        ]);
        $this->keyboard = fn (int $chatId) => collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => ($request['chat_id'] ?? null) === $chatId && isset($request['reply_markup']))
            ->last()['reply_markup']['inline_keyboard'] ?? [];
    });

    it('offers Platega to every customer, and the code arrives when the payment is checked', function () {
        ($this->press)(PLATEGA_BUYER, 'plan:month1');
        $order = TelegramStoreOrder::sole();
        expect(collect(($this->keyboard)(PLATEGA_BUYER))->flatten(1)->pluck('callback_data')->filter()->all())
            ->toContain("pay:online:{$order->public_id}")
            ->not->toContain("pay:card:{$order->public_id}");

        ($this->press)(PLATEGA_BUYER, "pay:online:{$order->public_id}");
        $order->refresh();
        $links = collect(($this->keyboard)(PLATEGA_BUYER))->flatten(1)->pluck('url')->filter()->values();
        expect($links)->toContain("https://pay.platega.io/?id={$order->payment_reference}");
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'transaction/process')
            && $request['metadata'] === ['userId' => (string) PLATEGA_BUYER, 'userName' => '@buyer']
            && $request['paymentDetails']['amount'] === 590);

        // Not paid yet: nothing happens.
        ($this->press)(PLATEGA_BUYER, "paid:{$order->public_id}");
        expect($order->refresh()->status)->toBe(TelegramStoreOrder::PENDING);

        ($this->pay)($order->payment_reference);
        ($this->press)(PLATEGA_BUYER, "paid:{$order->public_id}");
        // The callback arriving afterwards changes nothing.
        ($this->callback)($order->payment_reference)->assertOk();

        expect($order->refresh()->status)->toBe(TelegramStoreOrder::PAID)
            ->and(($this->sentTo)(PLATEGA_BUYER)->filter(fn ($text) => str_contains($text, 'Ваш код активации')))->toHaveCount(1)
            ->and(ActivationCode::count())->toBe(1);
    });

    it('sends the code to the chat when only the callback says the order is paid', function () {
        ($this->press)(PLATEGA_BUYER, 'plan:month12');
        $order = TelegramStoreOrder::sole();
        ($this->press)(PLATEGA_BUYER, "pay:online:{$order->public_id}");
        $order->refresh();

        ($this->pay)($order->payment_reference);
        ($this->callback)($order->payment_reference)->assertOk();

        expect($order->refresh()->status)->toBe(TelegramStoreOrder::PAID)
            ->and(($this->sentTo)(PLATEGA_BUYER)->last())->toContain('Ваш код активации');
        $this->getJson("/api/v1/store/orders/{$order->public_id}")
            ->assertJsonPath('data.order.channel', 'telegram')
            ->assertJsonPath('data.order.bot_url', 'https://t.me/RuAppStoreBot');
    });
});

it('tells the purchase page whether online payment is connected', function () {
    $this->getJson('/api/v1/store/offer')->assertJsonPath('data.online_payment', true);

    config(['services.platega.merchant_id' => '']);
    $this->getJson('/api/v1/store/offer')->assertJsonPath('data.online_payment', false);
});
