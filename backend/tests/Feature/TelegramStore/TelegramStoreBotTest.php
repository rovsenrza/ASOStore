<?php

use App\Enums\RoleSlug;
use App\Jobs\TelegramStoreBroadcastJob;
use App\Models\ActivationCode;
use App\Models\TelegramStoreBroadcast;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\TelegramStorePromoCode;
use App\Services\TelegramStore\BalanceLedger;
use App\Services\TelegramStore\Bot\AdminHandler;
use App\Services\TelegramStore\Bot\CustomerScreens;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\UpdateRouter;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\PromoService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const ADMIN_ID = 1000;
const TESTER_ID = 2000;
const BUYER_ID = 3000;

beforeEach(function () {
    config([
        'telegram_store.token' => 'test-token',
        'telegram_store.bot_username' => 'RuAppStoreBot',
        'telegram_store.admin_ids' => [(string) ADMIN_ID],
        'telegram_store.tester_ids' => [(string) TESTER_ID],
        'telegram_store.mock_payments' => true,
        'telegram_store.payments' => ['card' => 'https://pay.example/card', 'sbp' => null, 'paypal' => null],
        'telegram_store.welcome_banner' => '',
        'telegram_store.support_url' => 'https://t.me/support',
        'telegram_store.activation_url' => 'https://store.example/activate.html',
    ]);
    userWithRoles(RoleSlug::Admin);
    $this->blocked = [];
    // A test sets $this->channel to answer getChatMember (the channel subscription check).
    $this->channel = null;
    Http::fake(function (Request $request) {
        if ($this->channel !== null && str_ends_with($request->url(), '/getChatMember')) {
            return ($this->channel)($request);
        }
        if (in_array($request['chat_id'] ?? null, $this->blocked, true)) {
            return Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 77, 'photo' => [['file_id' => 'small'], ['file_id' => 'BIG']]]]);
    });

    $this->text = fn (int $userId, string $text) => app(UpdateRouter::class)->handle([
        'update_id' => 1,
        'message' => ['message_id' => 10, 'from' => ['id' => $userId, 'first_name' => 'U'.$userId], 'chat' => ['id' => $userId, 'type' => 'private'], 'text' => $text],
    ]);
    $this->press = fn (int $userId, string $data, bool $onPhoto = false) => app(UpdateRouter::class)->handle([
        'update_id' => 2,
        'callback_query' => [
            'id' => 'cb', 'data' => $data, 'from' => ['id' => $userId, 'first_name' => 'U'.$userId],
            'message' => ['message_id' => 50, 'chat' => ['id' => $userId, 'type' => 'private']]
                + ($onPhoto ? ['photo' => [['file_id' => 'BIG']], 'caption' => 'screen'] : ['text' => 'screen']),
        ],
    ]);
    // Texts the bot sent or edited into a chat, in order.
    $this->sentTo = fn (int $chatId) => collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => ($request['chat_id'] ?? null) === $chatId && isset($request['text']))
        ->map(fn (Request $request) => $request['text'])
        ->values();
    $this->lastKeyboard = fn (int $chatId) => collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => ($request['chat_id'] ?? null) === $chatId && isset($request['reply_markup']))
        ->last()['reply_markup']['inline_keyboard'] ?? [];
    $this->buttons = fn (int $chatId) => collect(($this->lastKeyboard)($chatId))->flatten(1)->pluck('callback_data')->filter()->values()->all();
});

function customer(int $userId): TelegramStoreCustomer
{
    return TelegramStoreCustomer::query()->where('telegram_user_id', $userId)->sole();
}

function giveBalance(int $userId, int $amount): void
{
    app(BalanceLedger::class)->change(customer($userId)->id, $amount, BalanceLedger::ADMIN_ADJUSTMENT);
}

it('opens the order for a plan chosen on the website', function () {
    ($this->text)(BUYER_ID, '/start buy_month6');
    $order = TelegramStoreOrder::sole();
    expect($order->plan_key)->toBe('month6')
        ->and($order->status)->toBe('PENDING')
        ->and($order->customer_id)->toBe(customer(BUYER_ID)->id)
        ->and(($this->sentTo)(BUYER_ID)->last())->toContain('Заказ #'.$order->shortReference())
        ->and(($this->buttons)(BUYER_ID))->toContain('cancel:'.$order->public_id);

    // A retired plan or a bare link shows the plans instead of guessing.
    ($this->text)(BUYER_ID, '/start buy_month99');
    expect(($this->buttons)(BUYER_ID))->toContain('plan:month12');
    ($this->text)(BUYER_ID, '/start buy');
    expect(($this->buttons)(BUYER_ID))->toContain('plan:month1')
        ->and(TelegramStoreOrder::count())->toBe(1);

    // A tester can pay it like any other order.
    ($this->text)(TESTER_ID, '/start buy_month1');
    $tester = TelegramStoreOrder::query()->where('telegram_user_id', TESTER_ID)->sole();
    ($this->press)(TESTER_ID, 'pay:card:'.$tester->public_id);
    ($this->press)(TESTER_ID, 'mockpay:'.$tester->public_id);
    expect($tester->refresh()->status)->toBe('PAID');
});

it('registers a customer on /start and shows the menu', function () {
    ($this->text)(BUYER_ID, '/start');

    expect(customer(BUYER_ID)->referral_code)->toMatch('/^[a-z0-9]{8}$/')
        ->and(($this->sentTo)(BUYER_ID)->last())->toContain('Ru App Store')->toContain('197₽ в месяц')
        ->and(($this->buttons)(BUYER_ID))->toContain('buy', 'profile', 'invite');
});

it('binds a referral from the start link and tells the referrer', function () {
    ($this->text)(TESTER_ID, '/start');
    $code = customer(TESTER_ID)->referral_code;

    ($this->text)(BUYER_ID, '/start ref_'.$code);
    // A second /start with another code does not rebind.
    ($this->text)(BUYER_ID, '/start ref_zzzzzzzz');

    expect(customer(BUYER_ID)->referrer_id)->toBe(customer(TESTER_ID)->id)
        ->and(($this->sentTo)(TESTER_ID)->last())->toContain('присоединился новый пользователь');
});

it('offers the new plans with savings', function () {
    ($this->text)(BUYER_ID, '/buy');

    $labels = collect(($this->lastKeyboard)(BUYER_ID))->flatten(1)->pluck('text')->all();
    expect($labels[0])->toBe('1 месяц — 590₽')
        ->and($labels[1])->toBe('6 месяцев — 1 770₽ · 295₽/мес · −50%')
        ->and($labels[2])->toBe('🔥 12 месяцев — 2 360₽ · 197₽/мес · −67%');
});

it('lets a tester complete a mock order end to end with a real code and referral bonus', function () {
    ($this->text)(ADMIN_ID, '/start');
    ($this->text)(TESTER_ID, '/start ref_'.customer(ADMIN_ID)->referral_code);

    ($this->press)(TESTER_ID, 'plan:month12');
    $order = TelegramStoreOrder::sole();
    expect($order->price_rub)->toBe(2360)->and($order->status)->toBe('PENDING')
        ->and(($this->buttons)(TESTER_ID))->toContain('pay:card:'.$order->public_id, 'pay:sbp:'.$order->public_id);

    ($this->press)(TESTER_ID, 'pay:card:'.$order->public_id);
    expect(($this->sentTo)(TESTER_ID)->last())->toContain('Тестовая оплата');

    ($this->press)(TESTER_ID, 'mockpay:'.$order->public_id);
    $order->refresh();
    $code = app(OrderService::class)->activationCode($order);

    expect($order->status)->toBe('PAID')
        ->and($order->payment_provider)->toBe('mock')
        ->and($order->referral_bonus_rub)->toBe(354)
        ->and(ActivationCode::sole()->duration_days)->toBe(365)
        ->and($code)->toMatch('/^[0-9A-Z]{4}(-[0-9A-Z]{4}){3}$/')
        ->and(($this->sentTo)(TESTER_ID)->last())->toContain($code)
        ->and(customer(ADMIN_ID)->balance_rub)->toBe(354)
        ->and(customer(ADMIN_ID)->referral_earned_rub)->toBe(354)
        ->and(($this->sentTo)(ADMIN_ID)->last())->toContain('+354₽');

    // The code stays available from the order list.
    ($this->press)(TESTER_ID, 'code:'.$order->public_id);
    expect(($this->sentTo)(TESTER_ID)->last())->toContain($code);
});

it('keeps mock payments away from regular customers', function () {
    ($this->text)(BUYER_ID, '/start');
    ($this->press)(BUYER_ID, 'plan:month1');
    $order = TelegramStoreOrder::sole();

    expect(($this->sentTo)(BUYER_ID)->last())->toContain('скоро будет доступна')
        ->and(collect(($this->buttons)(BUYER_ID))->filter(fn ($data) => str_starts_with($data, 'pay:')))->toBeEmpty();

    ($this->press)(BUYER_ID, 'mockpay:'.$order->public_id);
    ($this->press)(BUYER_ID, 'pay:card:'.$order->public_id);

    expect($order->refresh()->status)->toBe('PENDING')->and(ActivationCode::count())->toBe(0);
});

it('pays a whole order from the balance without a referral bonus', function () {
    ($this->text)(ADMIN_ID, '/start');
    ($this->text)(BUYER_ID, '/start ref_'.customer(ADMIN_ID)->referral_code);
    giveBalance(BUYER_ID, 1000);

    ($this->press)(BUYER_ID, 'plan:month1');
    $order = TelegramStoreOrder::sole();
    expect(($this->buttons)(BUYER_ID))->toContain('bal:'.$order->public_id);

    ($this->press)(BUYER_ID, 'bal:'.$order->public_id);

    expect($order->refresh()->status)->toBe('PAID')
        ->and($order->payment_provider)->toBe('balance')
        ->and($order->balance_used_rub)->toBe(590)
        ->and($order->amount_due_rub)->toBe(0)
        ->and(customer(BUYER_ID)->balance_rub)->toBe(410)
        ->and(customer(ADMIN_ID)->balance_rub)->toBe(0);
});

it('holds part of the balance on an order and returns it on cancel', function () {
    ($this->text)(BUYER_ID, '/start');
    giveBalance(BUYER_ID, 300);
    ($this->press)(BUYER_ID, 'plan:month6');
    $order = TelegramStoreOrder::sole();

    ($this->press)(BUYER_ID, 'bal:'.$order->public_id);
    expect($order->refresh()->amount_due_rub)->toBe(1470)->and(customer(BUYER_ID)->balance_rub)->toBe(0);

    ($this->press)(BUYER_ID, 'cancel:'.$order->public_id);
    expect($order->refresh()->status)->toBe('CANCELLED')
        ->and(customer(BUYER_ID)->balance_rub)->toBe(300)
        ->and(DB::table('telegram_store_balance_transactions')->sum('amount_rub'))->toBe('300');
});

it('replaces an older unpaid order when a new one is opened', function () {
    ($this->text)(BUYER_ID, '/start');
    ($this->press)(BUYER_ID, 'plan:month1');
    ($this->press)(BUYER_ID, 'plan:month6');

    expect(TelegramStoreOrder::query()->orderBy('id')->pluck('status')->all())->toBe(['CANCELLED', 'PENDING']);
});

it('expires unpaid orders, refunds held balance and invites the customer back', function () {
    ($this->text)(BUYER_ID, '/start');
    giveBalance(BUYER_ID, 100);
    ($this->press)(BUYER_ID, 'plan:month6');
    $order = TelegramStoreOrder::sole();
    ($this->press)(BUYER_ID, 'bal:'.$order->public_id);

    $this->travel(31)->minutes();
    $expired = app(OrderService::class)->expireStale();

    expect($expired)->toHaveCount(1)
        ->and($order->refresh()->status)->toBe('EXPIRED')
        ->and(customer(BUYER_ID)->balance_rub)->toBe(100);
});

describe('manual payment mode', function () {
    beforeEach(fn () => config(['telegram_store.mock_payments' => false]));

    it('sends the buyer to the payment link and lets an admin confirm after the window', function () {
        ($this->text)(BUYER_ID, '/start');
        ($this->press)(BUYER_ID, 'plan:month1');
        $order = TelegramStoreOrder::sole();
        expect(($this->buttons)(BUYER_ID))->toContain('pay:card:'.$order->public_id)->not->toContain('pay:sbp:'.$order->public_id);

        ($this->press)(BUYER_ID, 'pay:card:'.$order->public_id);
        $link = collect(($this->lastKeyboard)(BUYER_ID))->flatten(1)->pluck('url')->filter()->first();
        expect($link)->toBe('https://pay.example/card?order='.$order->reference().'&amount=590');

        ($this->press)(BUYER_ID, 'paid:'.$order->public_id);
        expect($order->refresh()->status)->toBe('REVIEW')
            ->and(($this->buttons)(ADMIN_ID))->toContain('adm:ok:'.$order->public_id);

        // The window passing must not lose a payment the customer already reported.
        $this->travel(2)->hours();
        app(OrderService::class)->expireStale();
        expect($order->refresh()->status)->toBe('REVIEW');

        ($this->press)(ADMIN_ID, 'adm:ok:'.$order->public_id);
        expect($order->refresh()->status)->toBe('PAID')
            ->and($order->reviewed_by)->toBe(ADMIN_ID)
            ->and(($this->sentTo)(BUYER_ID)->last())->toContain(app(OrderService::class)->activationCode($order));

        // A second admin tap does nothing.
        ($this->press)(ADMIN_ID, 'adm:ok:'.$order->public_id);
        expect(ActivationCode::count())->toBe(1);
    });

    it('confirms with /paid and rejects with a refund', function () {
        ($this->text)(BUYER_ID, '/start');
        ($this->press)(BUYER_ID, 'plan:month1');
        $first = TelegramStoreOrder::sole();
        ($this->text)(ADMIN_ID, '/paid '.$first->reference());
        expect($first->refresh()->status)->toBe('PAID');

        giveBalance(BUYER_ID, 200);
        ($this->press)(BUYER_ID, 'plan:month6');
        $second = TelegramStoreOrder::query()->where('status', 'PENDING')->sole();
        ($this->press)(BUYER_ID, 'bal:'.$second->public_id);
        ($this->press)(BUYER_ID, 'paid:'.$second->public_id);
        ($this->press)(ADMIN_ID, 'adm:no:'.$second->public_id);

        expect($second->refresh()->status)->toBe('REJECTED')
            ->and(customer(BUYER_ID)->balance_rub)->toBe(200)
            ->and(($this->sentTo)(BUYER_ID)->last())->toContain('не нашли оплату');
    });
});

it('ignores admin actions from other users', function () {
    ($this->text)(BUYER_ID, '/start');
    ($this->press)(BUYER_ID, 'plan:month1');
    $order = TelegramStoreOrder::sole();

    ($this->text)(BUYER_ID, '/paid '.$order->reference());
    ($this->press)(BUYER_ID, 'adm:ok:'.$order->public_id);
    ($this->press)(BUYER_ID, 'adm:price:month1');
    ($this->text)(BUYER_ID, '1');

    expect($order->refresh()->status)->toBe('PENDING')
        ->and(DB::table('telegram_store_settings')->count())->toBe(0);
});

it('lets admins change prices and the referral share', function () {
    ($this->press)(ADMIN_ID, 'adm:price:month1');
    ($this->text)(ADMIN_ID, 'abc');
    ($this->text)(ADMIN_ID, '490');
    ($this->press)(ADMIN_ID, 'adm:ref');
    ($this->text)(ADMIN_ID, '20');

    ($this->press)(BUYER_ID, 'plan:month1');
    expect(TelegramStoreOrder::sole()->price_rub)->toBe(490)
        ->and(($this->sentTo)(ADMIN_ID)->implode("\n"))->toContain('Нужна цена')->toContain('Реферальный бонус: 20%');
});

it('lets admins adjust a balance and tells the customer about a gift', function () {
    ($this->text)(BUYER_ID, '/start');
    ($this->text)(ADMIN_ID, '/user '.BUYER_ID);
    ($this->press)(ADMIN_ID, 'adm:bal:'.customer(BUYER_ID)->id);
    ($this->text)(ADMIN_ID, '-5');
    ($this->text)(ADMIN_ID, '250');

    expect(customer(BUYER_ID)->balance_rub)->toBe(250)
        ->and(($this->sentTo)(ADMIN_ID)->implode("\n"))->toContain('Нельзя списать больше')
        ->and(($this->sentTo)(BUYER_ID)->last())->toContain('Вам начислено 250₽');
});

it('reports sales without counting mock payments', function () {
    ($this->press)(TESTER_ID, 'plan:month1');
    ($this->press)(TESTER_ID, 'mockpay:'.TelegramStoreOrder::sole()->public_id);
    ($this->press)(ADMIN_ID, 'adm:stats:7');

    expect(($this->sentTo)(ADMIN_ID)->last())
        ->toContain('Оплачено: 0')
        ->toContain('Выручка: <b>0₽</b>')
        ->toContain('Тестовых оплат: 1');
});

it('queues a broadcast after a preview and delivers it, marking blocked users', function () {
    Queue::fake();
    ($this->text)(BUYER_ID, '/start');
    ($this->text)(TESTER_ID, '/start');

    ($this->press)(ADMIN_ID, 'adm:bc');
    app(UpdateRouter::class)->handle(['update_id' => 3, 'message' => [
        'message_id' => 99, 'from' => ['id' => ADMIN_ID], 'chat' => ['id' => ADMIN_ID, 'type' => 'private'], 'photo' => [['file_id' => 'x']], 'caption' => 'Скидка!',
    ]]);
    expect(($this->buttons)(ADMIN_ID))->toContain('adm:bcgo:99');

    ($this->press)(ADMIN_ID, 'adm:bcgo:99');
    ($this->press)(ADMIN_ID, 'adm:bcgo:99');
    Queue::assertPushed(TelegramStoreBroadcastJob::class, 1);

    $this->blocked = [TESTER_ID];
    app()->call([new TelegramStoreBroadcastJob(TelegramStoreBroadcast::sole()->id), 'handle']);

    $broadcast = TelegramStoreBroadcast::sole();
    expect($broadcast->status)->toBe('DONE')
        ->and($broadcast->sent)->toBe(2)
        ->and($broadcast->failed)->toBe(1)
        ->and(customer(TESTER_ID)->blocked_at)->not->toBeNull()
        ->and(($this->sentTo)(ADMIN_ID)->last())->toContain('Доставлено: 2');
});

it('puts the banner on every message, uploading it once and editing captions on navigation', function () {
    $banner = tempnam(sys_get_temp_dir(), 'banner');
    file_put_contents($banner, 'jpeg-bytes');
    config(['telegram_store.welcome_banner' => $banner]);

    ($this->text)(BUYER_ID, '/start');
    ($this->press)(BUYER_ID, 'buy', onPhoto: true);
    ($this->text)(BUYER_ID, '/profile');

    $calls = collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->reject(fn (Request $request) => str_ends_with($request->url(), 'answerCallbackQuery'))->values();
    $methods = $calls->map(fn (Request $request) => basename($request->url()))->all();
    expect($methods)->toBe(['sendPhoto', 'editMessageCaption', 'sendPhoto'])
        ->and($calls[0]->isMultipart())->toBeTrue()
        ->and($calls[1]['caption'])->toContain('Подписка Ru App Store')
        ->and($calls[2]['photo'])->toBe('BIG')
        ->and($calls[2]['caption'])->toContain('Мой профиль');
    unlink($banner);
});

it('keeps every screen within the caption limit', function () {
    ($this->text)(ADMIN_ID, '/start');
    ($this->text)(TESTER_ID, '/start ref_'.customer(ADMIN_ID)->referral_code);
    $customer = customer(TESTER_ID);
    $orders = app(OrderService::class);
    foreach (range(1, 12) as $i) {
        $orders->complete($orders->create($customer, 'month12'), 'mock');
    }
    $order = TelegramStoreOrder::query()->latest('id')->first();
    $screens = app(CustomerScreens::class);
    $admin = app(AdminHandler::class);

    $all = [
        $screens->menu(), $screens->plans($customer), $screens->order($orders->create($customer->refresh(), 'month6'), $customer),
        $screens->paid($order, 'AAAA-BBBB-CCCC-DDDD'), $screens->expired($order), $screens->rejected($order),
        $screens->profile($customer), $screens->orders($customer), $screens->code($order), $screens->invite($customer),
        $screens->help(), $screens->referralBonus(354, 99999), $admin->panel('Уведомление'), $admin->stats('all'),
    ];
    foreach ($all as $screen) {
        expect(Messenger::fitsCaption($screen->text))->toBeTrue(strip_tags($screen->text));
    }
});

describe('promo codes', function () {
    beforeEach(function () {
        $this->promo = fn (string $definition) => app(PromoService::class)->create(app(PromoService::class)->parse($definition), ADMIN_ID);
        $this->typeCode = function (int $userId, TelegramStoreOrder $order, string $code) {
            ($this->press)($userId, 'promo:'.$order->public_id);
            ($this->text)($userId, $code);
        };
    });

    it('lets an admin create a code from one line and rejects bad definitions', function () {
        ($this->press)(ADMIN_ID, 'adm:promonew');
        ($this->text)(ADMIN_ID, 'BLOG20 20');
        ($this->text)(ADMIN_ID, 'blog20 20% лимит 2 тариф 12 заметка Канал Вани');
        ($this->text)(ADMIN_ID, '/promo YEAR500 500₽ до 31.12.2099');
        ($this->text)(ADMIN_ID, '/promo YEAR500 500₽ до 31.12.2030');

        $blog = TelegramStorePromoCode::query()->where('code', 'BLOG20')->sole();
        expect($blog->type)->toBe('percent')->and($blog->value)->toBe(20)->and($blog->max_uses)->toBe(2)
            ->and($blog->plan_keys)->toBe(['month12'])->and($blog->note)->toBe('Канал Вани')
            ->and(TelegramStorePromoCode::query()->where('code', 'YEAR500')->sole()->type)->toBe('fixed')
            ->and(($this->sentTo)(ADMIN_ID)->implode("\n"))->toContain('Скидка: например')->toContain('не позже 2037')->toContain('start=promo_BLOG20');
    });

    it('discounts an order and pays the referral share on the discounted amount', function () {
        ($this->promo)('BLOG20 20%');
        ($this->text)(ADMIN_ID, '/start');
        ($this->text)(TESTER_ID, '/start ref_'.customer(ADMIN_ID)->referral_code);
        ($this->press)(TESTER_ID, 'plan:month12');
        $order = TelegramStoreOrder::sole();

        ($this->typeCode)(TESTER_ID, $order, 'blog20');
        expect($order->refresh()->discount_rub)->toBe(472)->and($order->amount_due_rub)->toBe(1888)
            ->and(($this->sentTo)(TESTER_ID)->last())->toContain('Промокод BLOG20: −472₽');

        ($this->press)(TESTER_ID, 'mockpay:'.$order->public_id);
        expect($order->refresh()->status)->toBe('PAID')->and($order->referral_bonus_rub)->toBe(283);

        // Once per customer.
        ($this->press)(TESTER_ID, 'plan:month1');
        ($this->typeCode)(TESTER_ID, TelegramStoreOrder::query()->where('status', 'PENDING')->sole(), 'BLOG20');
        expect(($this->sentTo)(TESTER_ID)->last())->toContain('уже использовали');
    });

    it('enforces the plan, the limit, expiry and the on/off switch', function () {
        $promo = ($this->promo)('ONLY12 10% тариф 12 лимит 1');
        ($this->press)(BUYER_ID, 'plan:month1');
        $order = TelegramStoreOrder::sole();

        ($this->typeCode)(BUYER_ID, $order, 'ONLY12');
        expect(($this->sentTo)(BUYER_ID)->last())->toContain('только для тарифа: 12 месяцев');

        ($this->press)(TESTER_ID, 'plan:month12');
        ($this->typeCode)(TESTER_ID, TelegramStoreOrder::query()->where('telegram_user_id', TESTER_ID)->sole(), 'ONLY12');
        ($this->press)(BUYER_ID, 'plan:month12');
        ($this->typeCode)(BUYER_ID, TelegramStoreOrder::query()->where('telegram_user_id', BUYER_ID)->where('status', 'PENDING')->sole(), 'ONLY12');
        expect(($this->sentTo)(BUYER_ID)->last())->toContain('Лимит активаций');

        ($this->press)(ADMIN_ID, 'adm:promotog:'.$promo->id);
        ($this->typeCode)(BUYER_ID, TelegramStoreOrder::query()->where('telegram_user_id', BUYER_ID)->where('status', 'PENDING')->sole(), 'NOPE');
        expect(($this->sentTo)(BUYER_ID)->last())->toContain('Такого промокода нет')
            ->and($promo->refresh()->active)->toBeFalse();
    });

    it('returns held balance when a code is applied and restores the price when removed', function () {
        ($this->promo)('MINUS300 300₽');
        ($this->text)(BUYER_ID, '/start');
        giveBalance(BUYER_ID, 100);
        ($this->press)(BUYER_ID, 'plan:month6');
        $order = TelegramStoreOrder::sole();
        ($this->press)(BUYER_ID, 'bal:'.$order->public_id);

        ($this->typeCode)(BUYER_ID, $order, 'MINUS300');
        expect($order->refresh()->amount_due_rub)->toBe(1470)->and($order->balance_used_rub)->toBe(0)
            ->and(customer(BUYER_ID)->balance_rub)->toBe(100);

        ($this->press)(BUYER_ID, 'unpromo:'.$order->public_id);
        expect($order->refresh()->amount_due_rub)->toBe(1770)->and($order->promo_code_id)->toBeNull();
    });

    it('completes an order a full discount covers', function () {
        ($this->promo)('FREE 100%');
        ($this->press)(BUYER_ID, 'plan:month1');
        $order = TelegramStoreOrder::sole();
        ($this->typeCode)(BUYER_ID, $order, 'FREE');

        expect($order->refresh()->status)->toBe('PAID')->and($order->payment_provider)->toBe('promo')
            ->and(($this->sentTo)(BUYER_ID)->last())->toContain('Оплата получена');
    });

    it('applies a code from a start link to the next order', function () {
        ($this->promo)('LINK15 15%');
        ($this->text)(BUYER_ID, '/start promo_LINK15');
        expect(($this->sentTo)(BUYER_ID)->last())->toContain('Промокод <b>LINK15</b> активирован');

        ($this->press)(BUYER_ID, 'plan:month1');
        expect(TelegramStoreOrder::sole()->discount_rub)->toBe(88);
    });
});

/** Answers the channel subscription check from $statuses (user ID => member status). */
function fakeChannel(object $test, ArrayObject $statuses): void
{
    config(['telegram_store.required_channel' => '@ruappstors']);
    $test->channel = function (Request $request) use ($statuses) {
        expect($request['chat_id'])->toBe('@ruappstors');

        return Http::response(['ok' => true, 'result' => ['status' => $statuses[$request['user_id']] ?? 'left']]);
    };
}

it('asks to subscribe to the channel before an order can be opened, then continues to the plan', function () {
    $statuses = new ArrayObject([BUYER_ID => 'left']);
    fakeChannel($this, $statuses);

    // From the website's «Оплатить в Telegram»: no order until they subscribe.
    ($this->text)(BUYER_ID, '/start buy_month6');
    expect(TelegramStoreOrder::count())->toBe(0)
        ->and(($this->sentTo)(BUYER_ID)->last())->toContain('Подпишитесь на наш канал')
        ->and(($this->buttons)(BUYER_ID))->toContain('sub:plan:month6');

    // «Я подписался» without subscribing: still no order.
    ($this->press)(BUYER_ID, 'sub:plan:month6');
    expect(TelegramStoreOrder::count())->toBe(0);

    $statuses[BUYER_ID] = 'member';
    ($this->press)(BUYER_ID, 'sub:plan:month6');
    expect(TelegramStoreOrder::sole()->plan_key)->toBe('month6');
});

it('keeps the plans and payment buttons behind the channel, but not the menu or profile', function () {
    fakeChannel($this, new ArrayObject([BUYER_ID => 'left', TESTER_ID => 'member']));

    ($this->press)(BUYER_ID, 'buy');
    expect(($this->buttons)(BUYER_ID))->toBe(['sub:buy', 'menu']);
    ($this->press)(BUYER_ID, 'plan:month1');
    expect(TelegramStoreOrder::count())->toBe(0);
    ($this->press)(BUYER_ID, 'profile');
    expect(($this->sentTo)(BUYER_ID)->last())->not->toContain('Подпишитесь');

    // A subscriber goes straight through.
    ($this->press)(TESTER_ID, 'plan:month1');
    expect(TelegramStoreOrder::sole()->customer_id)->toBe(customer(TESTER_ID)->id);
});

it('lets customers buy when Telegram cannot say whether they are subscribed', function () {
    config(['telegram_store.required_channel' => '@ruappstors']);
    $this->channel = fn () => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: member list is inaccessible'], 400);

    ($this->text)(BUYER_ID, '/start buy_month1');
    expect(TelegramStoreOrder::sole()->plan_key)->toBe('month1');
});
