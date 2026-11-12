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

it('keeps both widgets within Telegram\'s size limit', function () {
    foreach (['store', 'admin'] as $name) {
        $html = Widgets::html($name);
        expect(strlen($html))->toBeLessThanOrEqual(32768)
            ->and(strlen(gzcompress($html, 9)))->toBeLessThanOrEqual(4096);
    }
});

it('opens the storefront widget on /start, with the menu as fallback for old apps', function () {
    ($this->text)(W_BUYER, '/start');

    $sent = ($this->calls)('sendWidget')->sole();
    $state = json_decode($sent['chat_data'], true);
    expect($sent['allow_queries'])->toBeTrue()
        ->and($sent['html'])->toContain('TelegramWidget')
        ->and($sent['caption'])->toContain('Ru App Store')
        ->and(collect($sent['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data'))->toContain('buy')
        ->and(strlen($sent['chat_data']))->toBeLessThanOrEqual(Widgets::STATE_LIMIT)
        ->and(collect($state['p'])->pluck(0)->all())->toBe(['month1', 'month3', 'month6', 'month9', 'month12'])
        ->and($state['a'])->toBe([]);
    expect(($this->calls)('sendMessage'))->toHaveCount(0);
});

it('falls back to the plain menu when Telegram refuses the widget', function () {
    $this->refuseWidgets = true;
    ($this->text)(W_BUYER, '/start');

    expect(($this->calls)('sendMessage')->sole()['text'])->toContain('Ru App Store');
});

it('runs a tap like the matching button: a new-account order appears below the widget', function () {
    ($this->text)(W_BUYER, '/start');
    ($this->tap)(W_BUYER, 'reg:std:month1');

    expect(($this->calls)('answerWidgetQuery')->sole()['widget_query_id'])->toBe('wq1');
    $order = TelegramStoreOrder::query()->sole();
    expect($order->plan_key)->toBe('month1')
        ->and($order->status)->toBe(TelegramStoreOrder::PENDING)
        ->and(($this->calls)('sendMessage')->last()['text'])->toContain('590');
});

it('keeps admin taps away from customers', function () {
    ($this->tap)(W_BUYER, 'w:a:7');
    ($this->tap)(W_BUYER, 'adm:reviews');

    expect(($this->calls)('editMessageWidgetData'))->toHaveCount(0)
        ->and(($this->calls)('sendMessage'))->toHaveCount(0);
});

it('shows the admin dashboard widget and redraws it for another period in place', function () {
    ($this->text)(W_ADMIN, '/admin');
    $state = json_decode(($this->calls)('sendWidget')->sole()['chat_data'], true);
    expect($state['d'])->toBe(1)->and(collect($state['k'])->pluck(0))->toContain('выручка');

    ($this->tap)(W_ADMIN, 'w:a:30');
    $edit = ($this->calls)('editMessageWidgetData')->sole();
    expect($edit['message_id'])->toBe(88)->and(json_decode($edit['chat_data'], true)['d'])->toBe(30);

    ($this->tap)(W_ADMIN, 'adm:reviews');
    expect(($this->calls)('sendMessage')->last()['text'])->toContain('На проверке');
});
