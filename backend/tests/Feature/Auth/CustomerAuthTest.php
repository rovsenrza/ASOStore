<?php

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(fn () => (new RoleSeeder)->run());

describe('registration', function () {
    it('creates a customer, signs them in and audits it', function () {
        asBrowser()->postJson('/api/v1/auth/register', [
            'name' => 'Анна',
            'email' => ' Anna@Example.COM ',
            'password' => 'correct horse 42',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'anna@example.com')
            ->assertJsonPath('data.roles', ['customer'])
            ->assertJsonPath('data.subscription', null);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'anna@example.com');

        $user = User::sole();
        expect($user->hasRole(RoleSlug::Customer))->toBeTrue()
            ->and(password_get_info($user->password)['algoName'])->toBe('argon2id')
            ->and(AuditLog::where('action', 'auth.registered')->exists())->toBeTrue();
    });

    it('rejects weak passwords and duplicate emails with Russian messages', function () {
        User::factory()->create(['email' => 'taken@example.com']);

        asBrowser()->postJson('/api/v1/auth/register', [
            'name' => 'Анна',
            'email' => 'TAKEN@example.com',
            'password' => 'short',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.details.fields.email.0', 'Такое значение поля «эл. почта» уже используется.')
            ->assertJsonPath('error.details.fields.password.0', 'Поле «пароль» должно содержать не менее 10 символов.');
    });

    it('refuses session sign-in outside the browser origin', function () {
        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'x'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    });
});

describe('session login', function () {
    it('signs in, reports the account and signs out', function () {
        $user = userWithRoles(RoleSlug::Customer);

        asBrowser()->postJson('/api/v1/auth/login', ['email' => strtoupper($user->email), 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->public_id);

        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
        forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');

        expect(AuditLog::orderBy('id')->pluck('action')->all())->toBe(['auth.login', 'auth.logout'])
            ->and($user->fresh()->last_login_at)->not->toBeNull();
    });

    it('rejects a wrong password and audits the attempt', function () {
        $user = userWithRoles(RoleSlug::Customer);

        asBrowser()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

        $event = AuditLog::sole();
        expect($event->action)->toBe('auth.login_failed')
            // Masked: audit rows are immutable and must not keep a full address (P8-SEC-02).
            ->and($event->actor_label)->toBe(mb_substr($user->email, 0, 1).'***'.strstr($user->email, '@'));
    });

    it('refuses suspended accounts', function () {
        $user = userWithRoles(RoleSlug::Customer);
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        asBrowser()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    });

    it('rate-limits repeated attempts for one email', function () {
        $user = userWithRoles(RoleSlug::Customer);

        foreach (range(1, 5) as $attempt) {
            asBrowser()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }

        asBrowser()->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    });
});

describe('password reset', function () {
    it('answers the same way whether or not the account exists', function () {
        Notification::fake();
        $user = userWithRoles(RoleSlug::Customer);

        $known = $this->postJson('/api/v1/auth/password/forgot', ['email' => $user->email]);
        $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com']);

        $known->assertStatus(202)->assertJsonPath('data', ['accepted' => true]);
        $unknown->assertStatus(202)->assertJsonPath('data', ['accepted' => true]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user) {
            $mail = $notification->toMail($user);

            return str_starts_with($mail->subject, 'Сброс пароля')
                && str_contains($mail->actionUrl, '/reset-password.html?token=');
        });
        Notification::assertCount(1);
    });

    it('sets a new password and ends every existing session', function () {
        $user = userWithRoles(RoleSlug::Customer);
        $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'password'])->assertCreated();
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a brand new pass 7',
        ])->assertOk();

        expect($user->tokens()->count())->toBe(0)
            ->and($user->refreshTokens()->whereNull('revoked_at')->count())->toBe(0);
        $this->postJson('/api/v1/auth/tokens', ['email' => $user->email, 'password' => 'a brand new pass 7'])->assertCreated();
    });

    it('rejects an invalid reset token', function () {
        $user = userWithRoles(RoleSlug::Customer);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => 'not-a-token',
            'email' => $user->email,
            'password' => 'a brand new pass 7',
        ])->assertUnprocessable()->assertJsonPath('error.details.fields.token.0', 'Ссылка для сброса недействительна или устарела.');
    });
});
