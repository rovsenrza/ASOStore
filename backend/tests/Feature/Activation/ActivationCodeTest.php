<?php

use App\Enums\RoleSlug;
use App\Models\ActivationCode;
use App\Models\AuditLog;
use App\Models\Subscription;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->admin = userWithRoles(RoleSlug::Admin);
    $this->customer = userWithRoles(RoleSlug::Customer);
    $this->generate = function (array $overrides = []): array {
        $codes = asStaff($this->admin)->postJson('/api/v1/admin/activation-codes', $overrides + ['count' => 1, 'duration_days' => 30])
            ->assertCreated()
            ->json('data.codes');
        forgetGuards();

        return $codes;
    };
    $this->redeem = function (string $code, array $headers = []) {
        Sanctum::actingAs($this->customer);

        return $this->postJson('/api/v1/activation/redeem', ['code' => $code], $headers);
    };
});

it('returns plaintext codes once and stores only hashes', function () {
    $response = asStaff($this->admin)->postJson('/api/v1/admin/activation-codes', ['count' => 3, 'plan' => 'standard', 'duration_days' => 365, 'note' => 'Партия для теста'])
        ->assertCreated()
        ->assertJsonPath('data.count', 3);

    $codes = $response->json('data.codes');
    expect($codes)->each->toMatch('/^[0-9A-HJKMNP-TV-Z]{4}(-[0-9A-HJKMNP-TV-Z]{4}){3}$/');

    $stored = ActivationCode::all();
    expect($stored->pluck('code_hash')->all())->each->toHaveLength(64)
        ->and($stored->pluck('code_hint')->all())->toBe(array_map(fn ($code) => substr($code, -4), $codes));

    $audit = json_encode(AuditLog::where('action', 'activation_code.batch_created')->sole()->after);
    foreach ($codes as $code) {
        expect($audit)->not->toContain(str_replace('-', '', $code))->not->toContain($code);
    }
});

it('redeems a code into a subscription and moves the customer to device setup', function () {
    [$code] = ($this->generate)();

    ($this->redeem)(strtolower(str_replace('-', ' ', $code)))
        ->assertCreated()
        ->assertJsonPath('data.subscription.plan', 'standard')
        ->assertJsonPath('data.subscription.status', 'ACTIVE');

    $subscription = Subscription::sole();
    expect(now()->diffInDays($subscription->ends_at))->toBeGreaterThan(29)
        ->and(ActivationCode::sole()->redeemed_by)->toBe($this->customer->id);

    $this->getJson('/api/v1/storefront/status')->assertJsonPath('data.stage', 'device_required');
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.subscription.id', $subscription->public_id);
});

it('accepts Crockford look-alike characters', function () {
    [$code] = ($this->generate)();
    $typed = strtr($code, ['0' => 'O', '1' => 'l']);

    ($this->redeem)($typed)->assertCreated();
});

it('refuses reused, revoked, expired and unknown codes', function () {
    [$used, $revoked, $expiring] = ($this->generate)(['count' => 3]);
    ($this->redeem)($used)->assertCreated();

    ActivationCode::where('code_hint', substr($revoked, -4))->update(['status' => 'REVOKED']);
    ActivationCode::where('code_hint', substr($expiring, -4))->update(['expires_at' => now()->subMinute()]);

    ($this->redeem)($used)->assertStatus(409)->assertJsonPath('error.code', 'ACTIVATION_ALREADY_USED');
    ($this->redeem)($revoked)->assertUnprocessable()->assertJsonPath('error.code', 'ACTIVATION_INVALID');
    ($this->redeem)($expiring)->assertJsonPath('error.code', 'ACTIVATION_INVALID');
    ($this->redeem)('AAAA-BBBB-CCCC-DDDD')->assertJsonPath('error.code', 'ACTIVATION_INVALID');
    ($this->redeem)('short')->assertJsonPath('error.code', 'ACTIVATION_INVALID');
});

it('extends an active subscription instead of overlapping it', function () {
    [$first, $second] = ($this->generate)(['count' => 2, 'duration_days' => 30]);

    ($this->redeem)($first);
    ($this->redeem)($second);

    expect(now()->diffInDays($this->customer->fresh()->activeSubscription->ends_at))->toBeGreaterThan(59);
});

it('replays the stored response for a repeated Idempotency-Key', function () {
    [$code] = ($this->generate)();

    $first = ($this->redeem)($code, ['Idempotency-Key' => 'redeem-0001']);
    $replay = ($this->redeem)($code, ['Idempotency-Key' => 'redeem-0001']);

    $first->assertCreated();
    $replay->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($replay->json('data'))->toBe($first->json('data'))
        ->and(Subscription::count())->toBe(1);

    ($this->redeem)('AAAA-BBBB-CCCC-DDDD', ['Idempotency-Key' => 'redeem-0001'])
        ->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
});

it('does not create a second batch when generation is retried with the same key', function () {
    $headers = ['Idempotency-Key' => 'batch-2026-09-25'];

    $first = asStaff($this->admin)->postJson('/api/v1/admin/activation-codes', ['count' => 2], $headers)->assertCreated();
    $retry = $this->postJson('/api/v1/admin/activation-codes', ['count' => 2], $headers)->assertCreated();

    expect($retry->json('data.codes'))->toBe($first->json('data.codes'))
        ->and(ActivationCode::count())->toBe(2);
});

it('revokes only unused codes, with a reason', function () {
    [$code] = ($this->generate)();
    $record = ActivationCode::sole();

    asStaff($this->admin)->postJson("/api/v1/admin/activation-codes/{$record->public_id}/revoke", ['reason' => 'Выдан по ошибке'])
        ->assertOk()
        ->assertJsonPath('data.status', 'REVOKED');
    $this->postJson("/api/v1/admin/activation-codes/{$record->public_id}/revoke", ['reason' => 'again'])
        ->assertStatus(409);

    expect(AuditLog::where('action', 'activation_code.revoked')->sole()->reason)->toBe('Выдан по ошибке');
});

it('lists codes by effective status without exposing them', function () {
    ($this->generate)(['count' => 2]);
    ActivationCode::query()->first()->update(['expires_at' => now()->subDay()]);

    $list = asStaff($this->admin)->getJson('/api/v1/admin/activation-codes?status=EXPIRED')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'EXPIRED');

    expect($list->json('data.0'))->not->toHaveKey('code')->not->toHaveKey('code_hash');
    $this->getJson('/api/v1/admin/activation-codes?status=ISSUED')->assertJsonCount(1, 'data');
});

it('rate-limits redemption attempts', function () {
    foreach (range(1, 10) as $attempt) {
        ($this->redeem)('AAAA-BBBB-CCCC-DDDD')->assertUnprocessable();
    }

    ($this->redeem)('AAAA-BBBB-CCCC-DDDD')->assertStatus(429);
});
