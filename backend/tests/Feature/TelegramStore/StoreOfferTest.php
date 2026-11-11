<?php

use App\Services\TelegramStore\StoreSettings;
use Tests\Support\OpenApiContract;

it('lists the plans at their current prices, each with a deep link into the bot', function () {
    config([
        'telegram_store.bot_username' => 'RuAppStor_bot',
        'telegram_store.news_url' => 'https://t.me/ruappstors',
        'telegram_store.support_url' => 'https://t.me/suppruappstore',
    ]);
    // Admins change prices in the bot; the website follows.
    app(StoreSettings::class)->setPrice('month6', 1990);

    $response = $this->getJson('/api/v1/store/offer')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=300, public')
        ->assertJsonPath('data.currency', 'RUB')
        ->assertJsonPath('data.plans.0.id', 'month1')
        ->assertJsonPath('data.plans.0.saving_percent', 0)
        ->assertJsonPath('data.plans.1.id', 'month3')
        ->assertJsonPath('data.plans.3.id', 'month9')
        ->assertJsonPath('data.plans.2.price', 1990)
        ->assertJsonPath('data.plans.2.monthly_price', 332)
        ->assertJsonPath('data.plans.2.saving_percent', 44)
        ->assertJsonPath('data.plans.2.buy_url', 'https://t.me/RuAppStor_bot?start=buy_month6')
        ->assertJsonPath('data.plans.4.days', 365)
        ->assertJsonPath('data.plans.4.saving_percent', 67)
        ->assertJsonPath('data.referral_percent', 15)
        ->assertJsonPath('data.telegram.bot_url', 'https://t.me/RuAppStor_bot')
        ->assertJsonPath('data.telegram.news_url', 'https://t.me/ruappstors')
        ->assertJsonPath('data.telegram.support_url', 'https://t.me/suppruappstore');
    expect(OpenApiContract::errors($response->getContent(), 'StoreOfferResponse'))->toBe([]);
});

it('still lists the plans when no bot is configured', function () {
    config(['telegram_store.bot_username' => null, 'telegram_store.token' => null, 'telegram_store.news_url' => null]);

    $response = $this->getJson('/api/v1/store/offer')
        ->assertOk()
        ->assertJsonCount(5, 'data.plans')
        ->assertJsonPath('data.plans.0.buy_url', null)
        ->assertJsonPath('data.telegram.bot_url', null)
        ->assertJsonPath('data.telegram.news_url', null);
    expect(OpenApiContract::errors($response->getContent(), 'StoreOfferResponse'))->toBe([]);
});

it('gives a signed-in account a one-time bot link that carries the account', function () {
    config(['telegram_store.bot_username' => 'RuAppStoreBot']);
    $user = userWithRoles(App\Enums\RoleSlug::Customer);
    Laravel\Sanctum\Sanctum::actingAs($user);

    $url = $this->postJson('/api/v1/store/telegram-link')->assertOk()->json('data.url');
    expect($url)->toStartWith('https://t.me/RuAppStoreBot?start=acc_');

    $customer = App\Models\TelegramStoreCustomer::create(['telegram_user_id' => 42, 'chat_id' => 42, 'referral_code' => 'abcd1234']);
    $linker = app(App\Services\TelegramStore\AccountLinker::class);
    $token = substr($url, strrpos($url, 'acc_') + 4);
    expect($linker->consumeSiteToken($customer, $token)?->id)->toBe($user->id)
        ->and($linker->consumeSiteToken($customer, $token))->toBeNull()
        ->and(app(App\Services\TelegramStore\RenewalAccounts::class)->for($customer)->pluck('id')->all())->toBe([$user->id]);

    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/store/telegram-link')->assertUnauthorized();
});
