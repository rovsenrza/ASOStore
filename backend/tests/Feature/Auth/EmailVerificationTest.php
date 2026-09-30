<?php

use App\Enums\RoleSlug;
use App\Models\User;
use App\Notifications\EmailVerificationCodeNotification;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(function () {
    (new RoleSeeder)->run();
    Notification::fake();
    $this->register = fn () => asBrowser()->postJson('/api/v1/auth/register', [
        'name' => 'Анна',
        'email' => 'anna@example.com',
        'password' => 'correct horse 42',
    ])->assertCreated();
    $this->sentCode = function (): string {
        $code = null;
        Notification::assertSentTo(User::sole(), EmailVerificationCodeNotification::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return $code;
    };
});

it('emails a six-digit code on website registration and reports the email as unverified', function () {
    ($this->register)()->assertJsonPath('data.email_verified', false);

    expect(($this->sentCode)())->toMatch('/^\d{6}$/')
        ->and(DB::table('email_verification_codes')->value('code_hash'))->toHaveLength(64);
    $this->getJson('/api/v1/storefront/status')
        ->assertJsonPath('data.stage', 'email_verification_required')
        ->assertJsonPath('data.next_action', 'verify_email');
});

it('keeps an unverified website session away from activation until the code is entered', function () {
    ($this->register)();
    $code = ($this->sentCode)();

    $this->postJson('/api/v1/activation/redeem', ['code' => 'AAAA-BBBB-CCCC-DDDD'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    $this->getJson('/api/v1/account/export')->assertOk();

    $this->postJson('/api/v1/auth/email/verify', ['code' => substr($code, 0, 3).' '.substr($code, 3)])
        ->assertOk()
        ->assertJsonPath('data.email_verified', true);

    expect(User::sole()->email_verified_at)->not->toBeNull()
        ->and(DB::table('email_verification_codes')->count())->toBe(0);
    $this->getJson('/api/v1/storefront/status')->assertJsonPath('data.stage', 'activation_required');
    $this->postJson('/api/v1/activation/redeem', ['code' => 'AAAA-BBBB-CCCC-DDDD'])->assertJsonPath('error.code', 'ACTIVATION_INVALID');
});

it('lets the native app use an unverified account unchanged', function () {
    ($this->register)();
    forgetGuards();

    $token = $this->postJson('/api/v1/auth/tokens', ['email' => 'anna@example.com', 'password' => 'correct horse 42', 'device_name' => 'iPhone'])
        ->json('data.access_token');
    // The app sends only its bearer token, never the website's session cookie.
    $app = function () use ($token) {
        $this->flushSession();
        forgetGuards();

        return $this->withToken($token)->withoutHeader('Referer');
    };

    $app()->getJson('/api/v1/storefront/status')->assertJsonPath('data.stage', 'activation_required');
    $app()->postJson('/api/v1/activation/redeem', ['code' => 'AAAA-BBBB-CCCC-DDDD'])->assertJsonPath('error.code', 'ACTIVATION_INVALID');
});

it('counts wrong attempts, then asks for a new code', function () {
    ($this->register)();
    $code = ($this->sentCode)();
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->postJson('/api/v1/auth/email/verify', ['code' => $wrong])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.code.0', 'Неверный код. Осталось попыток: 4.');
    foreach (range(1, 4) as $ignored) {
        $this->postJson('/api/v1/auth/email/verify', ['code' => $wrong]);
    }
    $this->postJson('/api/v1/auth/email/verify', ['code' => $code])
        ->assertJsonPath('error.details.fields.code.0', 'Слишком много попыток. Запросите новый код.');
});

it('rejects an expired code', function () {
    ($this->register)();
    $code = ($this->sentCode)();
    $this->travel(16)->minutes();

    $this->postJson('/api/v1/auth/email/verify', ['code' => $code])
        ->assertJsonPath('error.details.fields.code.0', 'Код устарел. Запросите новый.');
});

it('resends at most once a minute and replaces the old code', function () {
    ($this->register)();
    $first = ($this->sentCode)();

    $this->postJson('/api/v1/auth/email/resend')->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');

    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/email/resend')->assertStatus(202)->assertJsonPath('data.resend_after', 60);
    Notification::assertSentToTimes(User::sole(), EmailVerificationCodeNotification::class, 2);
    $this->postJson('/api/v1/auth/email/verify', ['code' => $first])->assertUnprocessable();
});

it('treats a completed password reset as confirmation', function () {
    ($this->register)();
    $user = User::sole();
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/v1/auth/password/reset', ['token' => $token, 'email' => 'anna@example.com', 'password' => 'another horse 42'])->assertOk();
    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

it('treats a staff invitation as confirmation', function () {
    $admin = userWithRoles(RoleSlug::Admin);
    asStaff($admin)->postJson('/api/v1/admin/users', ['name' => 'Оператор', 'email' => 'ops@example.com', 'roles' => ['support']])->assertCreated();
    expect(User::where('email', 'ops@example.com')->sole()->email_verified_at)->not->toBeNull();
    Notification::assertSentTo(User::where('email', 'ops@example.com')->sole(), ResetPasswordNotification::class);
});
