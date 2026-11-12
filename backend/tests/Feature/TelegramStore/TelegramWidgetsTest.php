<?php

use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\Bot\UpdateRouter;
use App\Services\TelegramStore\Bot\Widgets;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

// Interactive widgets (Bot API 10.4): storefront on /start, dashboard on /admin.

const W_ADMIN = 1000;
const W_BUYER = 3000;

beforeEach(function () {
    config([
        'telegram_store.token' => 'test-token',
        'telegram_store.bot_username' => 'RuAppStoreBot',
        'telegram_store.admin_ids' => [(string) W_ADMIN],
        'telegram_store.tester_ids' => [],
        'telegram_store.mock_payments' => true,
        'telegram_store.payments' => ['card' => 'https://pay.example/card', 'sbp' => null, 'paypal' => null],
        'telegram_store.welcome_banner' => '',
        'telegram_store.widgets' => true,
    ]);
    $this->refuseWidgets = false;
    Http::fake(function (Request $request) {
        if ($this->refuseWidgets && str_ends_with($request->url(), '/sendWidget')) {
            return Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: method not found'], 400);
        }

        return Http::response(['ok' => true, 'result' => ['message_id' => 88]]);
    });
    $this->calls = fn (string $method) => collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with($r->url(), '/'.$method))->values();
    $this->text = fn (int $id, string $text) => app(UpdateRouter::class)->handle([
        'update_id' => 1,
        'message' => ['message_id' => 10, 'from' => ['id' => $id, 'first_name' => 'U'], 'chat' => ['id' => $id, 'type' => 'private'], 'text' => $text],
    ]);
    $this->tap = fn (int $id, string $data) => app(UpdateRouter::class)->handle([
        'update_id' => 2,
        'widget_query' => [
            'id' => 'wq1', 'from' => ['id' => $id, 'first_name' => 'U'], 'chat' => ['id' => $id, 'type' => 'private'],
            'message_id' => 88, 'chat_instance' => 'x', 'type' => ['type' => 'data'], 'data' => $data,
        ],
    ]);
});

function widgetState(Request $r): array
{
    return json_decode(($r['chat_data'] ?? '').($r['public_data'] ?? ''), true);
}

it('keeps the built app within Telegram\'s size limit and in step with its source', function () {
    $html = Widgets::html();
    expect(strlen($html))->toBeLessThanOrEqual(32768)
        ->and(strlen(gzcompress($html, 9)))->toBeLessThanOrEqual(4096)
        ->and($html)->toContain('data:image/webp;base64,')
        // Changed app.html without running scripts/build-telegram-widget.mjs?
        ->and(trim((string) file_get_contents(resource_path('telegram-widgets/app.min.sha1'))))
        ->toBe(sha1_file(resource_path('telegram-widgets/app.html')));
});

it('opens the storefront app on /start: widget only, no caption or keyboard', function () {
    ($this->text)(W_BUYER, '/start');

    $sent = ($this->calls)('sendWidget')->sole();
    $state = widgetState($sent);
    expect($sent['allow_queries'])->toBeTrue()
        ->and($sent->data())->not->toHaveKeys(['caption', 'reply_markup'])
        ->and(strlen($sent['chat_data']))->toBeLessThanOrEqual(Widgets::PART)
        ->and($state['v'])->toBe('s')
        ->and($state)->not->toHaveKey('i')
        ->and(collect($state['p'])->pluck(0)->all())->toBe(['month1', 'month3', 'month6', 'month9', 'month12']);
    expect(($this->calls)('sendMessage'))->toHaveCount(0);
});

it('falls back to the plain menu when Telegram refuses the widget', function () {
    $this->refuseWidgets = true;
    ($this->text)(W_BUYER, '/start');

    expect(($this->calls)('sendMessage')->sole()['text'])->toContain('Ru App Store');
});

it('navigates inside the same message and answers with the new screen', function () {
    ($this->tap)(W_BUYER, 'help');

    $edit = ($this->calls)('editMessageWidgetData')->sole();
    $state = widgetState($edit);
    expect($edit['message_id'])->toBe(88)
        ->and($state['v'])->toBe('g')
        ->and($state['n'])->toBe('help')
        ->and($state['x'])->not->toContain('<a ')
        ->and(($this->calls)('sendMessage'))->toHaveCount(0)
        ->and(($this->calls)('sendWidget'))->toHaveCount(0);
    $answer = ($this->calls)('answerWidgetQuery')->sole()['data'];
    expect($answer === 'r' || json_decode($answer, true)['v'] === 'g')->toBeTrue();
});

it('opens an order from the storefront in place', function () {
    ($this->tap)(W_BUYER, 'reg:std:month1');

    $order = TelegramStoreOrder::query()->sole();
    $state = widgetState(($this->calls)('editMessageWidgetData')->sole());
    expect($order->plan_key)->toBe('month1')
        ->and($order->status)->toBe(TelegramStoreOrder::PENDING)
        ->and(json_encode($state, JSON_UNESCAPED_UNICODE))->toContain('590')
        ->and(collect($state['b'])->flatten(1)->pluck(1))->toContain('cancel:'.$order->public_id);
});

it('keeps every state within the two data parts, splitting at a character boundary', function () {
    $long = new \App\Services\TelegramStore\Bot\Screen('<b>Длинно</b>'."\n".str_repeat('Очень длинный текст экрана. ', 200), [[['text' => 'Ок', 'callback_data' => 'menu']]]);
    $state = app(Widgets::class)->fromScreen($long);
    [$chat, $public] = Widgets::parts($state);
    expect(strlen($chat))->toBeLessThanOrEqual(Widgets::PART)
        ->and(strlen($public))->toBeLessThanOrEqual(Widgets::PART)
        ->and(mb_check_encoding($chat, 'UTF-8'))->toBeTrue()
        ->and(json_decode($chat.$public, true)['t'])->toBe('Длинно');
});

it('keeps admin taps away from customers', function () {
    ($this->tap)(W_BUYER, 'w:a:7');
    ($this->tap)(W_BUYER, 'adm:reviews');

    expect(($this->calls)('editMessageWidgetData'))->toHaveCount(0)
        ->and(($this->calls)('sendMessage'))->toHaveCount(0);
});

it('shows the admin dashboard and its screens in the same message', function () {
    ($this->text)(W_ADMIN, '/admin');
    expect(widgetState(($this->calls)('sendWidget')->sole())['v'])->toBe('d');

    ($this->tap)(W_ADMIN, 'w:a:30');
    expect(widgetState(($this->calls)('editMessageWidgetData')->last())['d'])->toBe(30);

    ($this->tap)(W_ADMIN, 'adm:reviews');
    $state = widgetState(($this->calls)('editMessageWidgetData')->last());
    expect($state['n'])->toBe('adm:reviews')->and($state['nv'][0][2])->toBe('adm:panel')->and($state['t'])->toContain('На проверке');
});

it('sends news (a paid order) as a new widget message', function () {
    $customer = \App\Models\TelegramStoreCustomer::query()->create(['telegram_user_id' => W_BUYER, 'chat_id' => W_BUYER, 'first_name' => 'B', 'referral_code' => 'wbuyer01']);
    app(\App\Services\TelegramStore\Bot\Messenger::class)->notify(W_BUYER, new \App\Services\TelegramStore\Bot\Screen("<b>✅ Оплата получена</b>\nКод: <code>AAAA-BBBB</code>"));

    $state = widgetState(($this->calls)('sendWidget')->sole());
    expect($state['t'])->toBe('✅ Оплата получена')->and($state['x'])->toContain('<code>AAAA-BBBB</code>');
});
