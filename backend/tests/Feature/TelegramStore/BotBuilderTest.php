<?php

use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\TelegramStoreCustomer;
use App\Services\TelegramStore\Bot\UpdateRouter;
use App\Services\TelegramStore\StoreSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// The admin grows the bot from the web panel: own pages, commands, keywords, hidden buttons, bot admins.

const BB_ENV_ADMIN = 7100;
const BB_BUYER = 7200;
const BB_NEW_ADMIN = 7300;

beforeEach(function () {
    config([
        'telegram_store.token' => 'test-token',
        'telegram_store.admin_ids' => [(string) BB_ENV_ADMIN],
        'telegram_store.mock_payments' => true,
        'telegram_store.welcome_banner' => '',
        'telegram_store.support_url' => 'https://t.me/support',
        'telegram_store.news_url' => 'https://t.me/news',
    ]);
    Http::fake(fn () => Http::response(['ok' => true, 'result' => ['message_id' => 77]]));
    $this->admin = userWithRoles(RoleSlug::Admin);

    $this->text = fn (int $userId, string $text) => app(UpdateRouter::class)->handle([
        'update_id' => 1,
        'message' => ['message_id' => 10, 'from' => ['id' => $userId, 'first_name' => 'U'.$userId, 'username' => 'user'.$userId], 'chat' => ['id' => $userId, 'type' => 'private'], 'text' => $text],
    ]);
    $this->press = fn (int $userId, string $data) => app(UpdateRouter::class)->handle([
        'update_id' => 2,
        'callback_query' => ['id' => 'cb', 'data' => $data, 'from' => ['id' => $userId, 'first_name' => 'U'.$userId],
            'message' => ['message_id' => 50, 'chat' => ['id' => $userId, 'type' => 'private'], 'text' => 'screen']],
    ]);
    $this->sent = fn (int $chatId) => collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => ($request['chat_id'] ?? null) === $chatId && isset($request['text']))
        ->map(fn (Request $request) => $request['text'])->values();
    $this->keyboard = fn (int $chatId) => collect(collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => ($request['chat_id'] ?? null) === $chatId && isset($request['reply_markup']))
        ->last()['reply_markup']['inline_keyboard'] ?? [])->flatten(1);

    $this->save = fn (array $blocks, array $hidden = []) => asStaff($this->admin)->putJson('/api/v1/admin/bot-builder', ['blocks' => $blocks, 'hidden_menu_items' => $hidden]);
});

it('adds a page with a menu button, a command and keywords', function () {
    ($this->save)([[
        'label' => '❓ Частые вопросы', 'text' => '<b>Ответы</b> на вопросы & цены < 500', 'url' => 'https://ruappstore.com/support.html', 'url_label' => 'Сайт',
        'command' => '/faq', 'keywords' => ['вопрос', 'FAQ'], 'in_menu' => true, 'enabled' => true,
    ]])->assertOk()->assertJsonPath('data.blocks.0.id', 'chastye_voprosy')->assertJsonPath('data.blocks.0.command', 'faq');
    // Stray & and < are escaped so Telegram accepts the HTML.
    expect(app(StoreSettings::class)->botBlocks()[0]['text'])->toBe('<b>Ответы</b> на вопросы &amp; цены &lt; 500');

    ($this->text)(BB_BUYER, '/start');
    expect(($this->keyboard)(BB_BUYER)->pluck('callback_data'))->toContain('cb:chastye_voprosy');

    ($this->press)(BB_BUYER, 'cb:chastye_voprosy');
    expect(($this->sent)(BB_BUYER)->last())->toContain('<b>Ответы</b>')
        ->and(($this->keyboard)(BB_BUYER)->pluck('url'))->toContain('https://ruappstore.com/support.html');

    ($this->text)(BB_BUYER, '/faq');
    expect(($this->sent)(BB_BUYER)->last())->toContain('Ответы');
    ($this->text)(BB_BUYER, 'у меня вопрос по оплате');
    expect(($this->sent)(BB_BUYER)->last())->toContain('Ответы');

    // The new command is registered in Telegram's «/» menu.
    expect(collect(Http::recorded())->contains(fn ($pair) => str_ends_with($pair[0]->url(), '/setMyCommands')
        && collect($pair[0]['commands'])->contains('command', 'faq')))->toBeTrue();
});

it('refuses broken HTML, taken commands, and hides standard buttons', function () {
    ($this->save)([['label' => 'X', 'text' => '<b>не закрыт']])->assertUnprocessable();
    ($this->save)([['label' => 'X', 'text' => '<div>нет</div>']])->assertUnprocessable();
    ($this->save)([['label' => 'X', 'text' => 'ok', 'command' => 'buy']])->assertUnprocessable();

    ($this->save)([], ['invite', 'news'])->assertOk();
    ($this->text)(BB_BUYER, '/start');
    $menu = ($this->keyboard)(BB_BUYER);
    expect($menu->pluck('callback_data'))->not->toContain('invite')->toContain('buy', 'renew', 'profile')
        ->and($menu->pluck('url'))->not->toContain('https://t.me/news');
});

it('hides a disabled page and says so when its old button is pressed', function () {
    ($this->save)([['id' => 'promo', 'label' => 'Акция', 'text' => 'Скидка', 'enabled' => false]])->assertOk();

    ($this->text)(BB_BUYER, '/start');
    expect(($this->keyboard)(BB_BUYER)->pluck('callback_data'))->not->toContain('cb:promo');
    ($this->press)(BB_BUYER, 'cb:promo');
    expect(collect(Http::recorded())->contains(fn ($pair) => str_ends_with($pair[0]->url(), '/answerCallbackQuery')
        && str_contains((string) $pair[0]['text'], 'недоступен')))->toBeTrue();
});

it('adds and removes bot admins from the panel, keeping the server ones', function () {
    TelegramStoreCustomer::create(['telegram_user_id' => BB_NEW_ADMIN, 'chat_id' => BB_NEW_ADMIN, 'username' => 'helper', 'first_name' => 'Helper', 'referral_code' => Str::lower(Str::random(8))]);

    asStaff(userWithRoles(RoleSlug::Support))->postJson('/api/v1/admin/bot-admins', ['query' => '@helper'])->assertForbidden();
    forgetGuards();
    asStaff($this->admin)->postJson('/api/v1/admin/bot-admins', ['query' => '@helper'])
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.username', 'helper')->assertJsonPath('data.0.locked', true);
    $this->postJson('/api/v1/admin/bot-admins', ['query' => '@nobody'])->assertNotFound();

    // The new admin gets the bot's admin panel.
    app(StoreSettings::class)->flush();
    ($this->text)(BB_NEW_ADMIN, '/admin');
    expect(($this->sent)(BB_NEW_ADMIN)->last())->toContain('Админ-панель');

    $this->deleteJson('/api/v1/admin/bot-admins/'.BB_ENV_ADMIN)->assertStatus(409);
    $this->deleteJson('/api/v1/admin/bot-admins/'.BB_NEW_ADMIN)->assertOk()->assertJsonCount(1, 'data');
    expect(app(StoreSettings::class)->isAdmin(BB_NEW_ADMIN))->toBeFalse()
        ->and(AuditLog::where('action', 'store.bot_admin_added')->count())->toBe(1);
});

it('sends a preview only to a bot admin', function () {
    asStaff($this->admin)->postJson('/api/v1/admin/bot-builder/preview', ['text' => '<i>Тест</i>'])->assertOk()->assertJsonPath('data.sent_to', BB_ENV_ADMIN);
    $this->postJson('/api/v1/admin/bot-builder/preview', ['text' => '<i>Тест</i>', 'telegram_user_id' => BB_BUYER])->assertStatus(409);
});
