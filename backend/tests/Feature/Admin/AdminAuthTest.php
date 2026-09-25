<?php

use App\Enums\RoleSlug;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\TotpService;
use Laravel\Sanctum\Sanctum;

function totpCode(User $user): string
{
    return app(TotpService::class)->currentCode($user->fresh()->totp_secret);
}

it('enrols an authenticator on the first staff sign-in', function () {
    $admin = userWithRoles(RoleSlug::Admin);

    $step = asBrowser()->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.next_step', 'totp_enrollment')
        ->json('data.enrollment');

    expect($step['secret'])->toMatch('/^[A-Z2-7]{32}$/')
        ->and($step['otpauth_uri'])->toStartWith('otpauth://totp/')
        ->and($step['qr_svg'])->toContain('<svg');

    // The password alone does not open the admin API.
    $this->getJson('/api/v1/admin/auth/me')->assertUnauthorized();

    // The invalidated session still carries a CSRF token for the browser's next request.
    expect(app('session.store')->token())->toBeString()->not->toBeEmpty();

    $this->postJson('/api/v1/admin/auth/totp', ['code' => totpCode($admin)])
        ->assertOk()
        ->assertJsonPath('data.email', $admin->email)
        ->assertJsonPath('data.roles', ['admin'])
        ->assertJsonPath('data.permissions', ['users.view', 'users.manage', 'activation-codes.view', 'activation-codes.manage', 'audit.view', 'catalog.view', 'catalog.manage', 'devices.view', 'devices.manage', 'devices.reveal-udid']);

    $this->getJson('/api/v1/admin/auth/me')->assertOk();
    expect($admin->fresh()->totp_confirmed_at)->not->toBeNull()
        ->and(AuditLog::orderBy('id')->pluck('action')->all())->toBe(['admin.totp_enrolled', 'admin.login']);
});

it('asks enrolled staff for a code and rejects wrong or replayed codes', function () {
    $support = userWithRoles(RoleSlug::Support);
    asBrowser()->postJson('/api/v1/admin/auth/login', ['email' => $support->email, 'password' => 'password']);
    $code = totpCode($support);
    $this->postJson('/api/v1/admin/auth/totp', ['code' => $code])->assertOk();
    $this->postJson('/api/v1/admin/auth/logout')->assertOk();
    forgetGuards();

    $this->postJson('/api/v1/admin/auth/login', ['email' => $support->email, 'password' => 'password'])
        ->assertJsonPath('data.next_step', 'totp')
        ->assertJsonPath('data.enrollment', null);

    $wrong = $code === '000000' ? '111111' : '000000';
    $this->postJson('/api/v1/admin/auth/totp', ['code' => $wrong])->assertUnprocessable()->assertJsonPath('error.code', 'TOTP_INVALID');
    $this->postJson('/api/v1/admin/auth/totp', ['code' => $code])->assertJsonPath('error.code', 'TOTP_INVALID');
});

it('expires the pending second step', function () {
    $admin = userWithRoles(RoleSlug::Admin);
    asBrowser()->postJson('/api/v1/admin/auth/login', ['email' => $admin->email, 'password' => 'password']);

    $this->travel(6)->minutes();

    $this->postJson('/api/v1/admin/auth/totp', ['code' => totpCode($admin)])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'TOTP_REQUIRED');
});

it('keeps customers out of the admin sign-in', function () {
    $customer = userWithRoles(RoleSlug::Customer);

    asBrowser()->postJson('/api/v1/admin/auth/login', ['email' => $customer->email, 'password' => 'password'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

it('refuses staff bearer tokens and sessions without the TOTP step', function () {
    $admin = userWithRoles(RoleSlug::Admin);

    Sanctum::actingAs($admin);
    $this->getJson('/api/v1/admin/users')->assertUnauthorized()->assertJsonPath('error.code', 'TOTP_REQUIRED');

    forgetGuards();
    asBrowser()->actingAs($admin, 'web')->getJson('/api/v1/admin/users')->assertJsonPath('error.code', 'TOTP_REQUIRED');
});
