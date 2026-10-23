<?php

use App\Enums\RoleSlug;
use App\Services\TelegramStore\Bot\CustomerScreens;
use App\Services\TelegramStore\StoreSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

it('lets the bot greeting and brand be overridden, and falls back to defaults', function () {
    $settings = app(StoreSettings::class);
    $screens = app(CustomerScreens::class);

    // Brand override shows through the generated greeting.
    $settings->setBrand('Моя Лавка');
    expect($screens->welcomeText())->toContain('Моя Лавка');

    // A custom greeting replaces the generated text entirely.
    $settings->setWelcomeText('Привет, друг! 👋');
    expect($screens->welcomeText())->toBe('Привет, друг! 👋');

    // Clearing it restores the generated greeting (which still carries the brand).
    $settings->setWelcomeText('');
    expect($screens->welcomeText())->toContain('Моя Лавка')->not->toBe('Привет, друг! 👋');
});

it('shows and updates bot content for an admin', function () {
    $admin = userWithRoles(RoleSlug::Admin);

    asStaff($admin)->putJson('/api/v1/admin/bot-content', [
        'welcome_text' => 'Добро пожаловать!',
        'brand' => 'Лавка',
    ])->assertOk()
        ->assertJsonPath('data.welcome_text', 'Добро пожаловать!')
        ->assertJsonPath('data.welcome_text_is_custom', true)
        ->assertJsonPath('data.brand', 'Лавка');

    asStaff($admin)->getJson('/api/v1/admin/bot-content')
        ->assertOk()->assertJsonPath('data.brand', 'Лавка');

    expect(app(StoreSettings::class)->welcomeText())->toBe('Добро пожаловать!');
});

it('uploads and resets the banner', function () {
    $admin = userWithRoles(RoleSlug::Admin);

    asStaff($admin)->post('/api/v1/admin/bot-content/banner', [
        'banner' => UploadedFile::fake()->image('welcome.jpg', 800, 600),
    ])->assertOk()->assertJsonPath('data.banner_set', true);

    asStaff($admin)->get('/api/v1/admin/bot-content/banner')->assertOk();

    // Reset clears the override; the config-default banner (if present) becomes effective again.
    asStaff($admin)->deleteJson('/api/v1/admin/bot-content/banner')
        ->assertOk()->assertJsonPath('data.banner_is_default', true);
});

it('rejects a non-image banner', function () {
    asStaff(userWithRoles(RoleSlug::Admin))->post('/api/v1/admin/bot-content/banner', [
        'banner' => UploadedFile::fake()->create('x.pdf', 20, 'application/pdf'),
    ])->assertStatus(422);
});

it('lets support view but not edit bot content', function () {
    $support = userWithRoles(RoleSlug::Support);
    asStaff($support)->getJson('/api/v1/admin/bot-content')->assertOk();
    asStaff($support)->putJson('/api/v1/admin/bot-content', ['welcome_text' => 'x', 'brand' => 'y'])->assertForbidden();
});
