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
        ->assertJsonPath('data.plans.1.price', 1990)
        ->assertJsonPath('data.plans.1.monthly_price', 332)
        ->assertJsonPath('data.plans.1.saving_percent', 44)
        ->assertJsonPath('data.plans.1.buy_url', 'https://t.me/RuAppStor_bot?start=buy_month6')
        ->assertJsonPath('data.plans.2.days', 365)
        ->assertJsonPath('data.plans.2.saving_percent', 67)
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
        ->assertJsonCount(3, 'data.plans')
        ->assertJsonPath('data.plans.0.buy_url', null)
        ->assertJsonPath('data.telegram.bot_url', null)
        ->assertJsonPath('data.telegram.news_url', null);
    expect(OpenApiContract::errors($response->getContent(), 'StoreOfferResponse'))->toBe([]);
});
