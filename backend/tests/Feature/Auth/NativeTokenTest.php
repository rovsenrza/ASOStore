<?php

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\RefreshToken;

beforeEach(function () {
    $this->user = userWithRoles(RoleSlug::Customer);
    $this->issue = fn () => $this->postJson('/api/v1/auth/tokens', [
        'email' => $this->user->email,
        'password' => 'password',
        'device_name' => 'iPhone Анны',
    ])->assertCreated()->json('data');
});

it('issues a short-lived access token and a refresh token', function () {
    $pair = ($this->issue)();

    expect($pair['token_type'])->toBe('Bearer')
        ->and(now()->diffInMinutes($pair['access_token_expires_at']))->toBeGreaterThan(14)->toBeLessThanOrEqual(15)
        ->and(now()->diffInDays($pair['refresh_token_expires_at']))->toBeGreaterThan(29)
        ->and($pair['user']['id'])->toBe($this->user->public_id);

    $this->withToken($pair['access_token'])->getJson('/api/v1/auth/me')->assertOk();

    // Only a hash of the refresh token is stored.
    expect(RefreshToken::sole()->token_hash)->toBe(hash('sha256', $pair['refresh_token']));
});

it('rejects wrong credentials', function () {
    $this->postJson('/api/v1/auth/tokens', ['email' => $this->user->email, 'password' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
});

it('stops accepting the access token after 15 minutes', function () {
    $pair = ($this->issue)();

    $this->travel(16)->minutes();

    $this->withToken($pair['access_token'])->getJson('/api/v1/auth/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('rotates tokens on refresh', function () {
    $first = ($this->issue)();

    $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->assertOk()->json('data');

    expect($second['refresh_token'])->not->toBe($first['refresh_token'])
        ->and($second['access_token'])->not->toBe($first['access_token']);

    forgetGuards();
    $this->withToken($first['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
    forgetGuards();
    $this->withToken($second['access_token'])->getJson('/api/v1/auth/me')->assertOk();

    $spent = RefreshToken::query()->whereNotNull('used_at')->sole();
    expect($spent->replaced_by_id)->toBe(RefreshToken::query()->whereNull('used_at')->sole()->id);
});

it('revokes the whole family when a spent refresh token is reused', function () {
    $first = ($this->issue)();
    $second = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])->json('data');

    // An attacker replays the first token.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $first['refresh_token']])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'SESSION_EXPIRED');

    // The legitimate, newer tokens are dead too.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $second['refresh_token']])
        ->assertJsonPath('error.code', 'SESSION_EXPIRED');
    forgetGuards();
    $this->withToken($second['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();

    expect(AuditLog::where('action', 'auth.refresh_reuse_detected')->count())->toBeGreaterThanOrEqual(1)
        ->and(RefreshToken::whereNull('revoked_at')->count())->toBe(0);
});

it('keeps other sign-ins alive when one family is revoked', function () {
    $phone = ($this->issue)();
    $tablet = ($this->issue)();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $phone['refresh_token']]);
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $phone['refresh_token']]);

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tablet['refresh_token']])->assertOk();
});

it('treats expired and unknown refresh tokens as signed out', function () {
    $pair = ($this->issue)();
    $this->travel(31)->days();

    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertJsonPath('error.code', 'SESSION_EXPIRED');
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'never-issued'])->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('revokes the native session on logout', function () {
    $pair = ($this->issue)();

    $this->withToken($pair['access_token'])->postJson('/api/v1/auth/logout')->assertOk();

    forgetGuards();
    $this->withToken($pair['access_token'])->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertJsonPath('error.code', 'SESSION_EXPIRED');
});

it('cuts off suspended accounts on their next request', function () {
    $pair = ($this->issue)();
    $this->user->forceFill(['status' => UserStatus::Suspended])->save();

    $this->withToken($pair['access_token'])->getJson('/api/v1/auth/me')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

    expect($this->user->tokens()->count())->toBe(0);
});
