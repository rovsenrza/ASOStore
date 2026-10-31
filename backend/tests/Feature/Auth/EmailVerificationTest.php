<?php

use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    (new RoleSeeder)->run();
    Notification::fake();
});

it('registers on the website without an email code step', function () {
    asBrowser()->postJson('/api/v1/auth/register', [
        'name' => 'Анна',
        'email' => 'anna@example.com',
        'password' => 'correct horse 42',
    ])->assertCreated()->assertJsonPath('data.email_verified', true);

    Notification::assertNothingSent();
    expect(User::sole()->email_verified_at)->not->toBeNull();
    $this->getJson('/api/v1/storefront/status')->assertJsonPath('data.stage', 'activation_required');
    $this->postJson('/api/v1/activation/redeem', ['code' => 'AAAA-BBBB-CCCC-DDDD'])->assertJsonPath('error.code', 'ACTIVATION_INVALID');
});

it('does not hold back accounts registered before the code step was removed', function () {
    asBrowser()->postJson('/api/v1/auth/register', [
        'name' => 'Анна',
        'email' => 'anna@example.com',
        'password' => 'correct horse 42',
    ])->assertCreated();
    User::sole()->forceFill(['email_verified_at' => null])->save();

    $this->postJson('/api/v1/activation/redeem', ['code' => 'AAAA-BBBB-CCCC-DDDD'])->assertJsonPath('error.code', 'ACTIVATION_INVALID');
    Notification::assertNotSentTo(User::sole(), EmailVerificationCodeNotification::class);
});
