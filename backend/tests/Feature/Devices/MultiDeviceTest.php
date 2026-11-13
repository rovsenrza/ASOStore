<?php

use App\Enums\RoleSlug;
use App\Models\ActivationCode;
use App\Models\Device;
use App\Models\Subscription;
use App\Services\Activation\ActivationCodeService;
use App\Services\Devices\UdidHasher;
use Laravel\Sanctum\Sanctum;

// Several iPhones per account, each paid for on its own (decided 2026-11-13).

const IPHONE_A = '00008030-00000000000000A1';
const IPHONE_B = '00008030-00000000000000B2';

beforeEach(function () {
    connectFakeAppleTeam();
    $this->user = userWithRoles(RoleSlug::Customer);
    $this->codes = app(ActivationCodeService::class);
    // The account's first iPhone: a 30-day code, then the iPhone connects.
    $this->a = $this->codes->redeem($this->user, issueActivationCode(30))->device;
    connectIphone($this->user, IPHONE_A);
    $this->a->refresh();
});

it('extends the only iPhone when no device is named', function () {
    $end = $this->a->accessEndsAt();
    $this->codes->redeem($this->user, issueActivationCode(30));

    expect($this->a->accessEndsAt()->equalTo($end->copy()->addDays(30)))->toBeTrue()
        ->and(Device::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('opens a pending iPhone for a «new device» code; each iPhone has its own term', function () {
    $b = $this->codes->redeem($this->user, issueActivationCode(90), newDevice: true)->device;

    expect($b->isEnrolled())->toBeFalse()
        ->and($b->hasAccess())->toBeTrue()
        ->and((int) round(now()->diffInDays($b->accessEndsAt())))->toBe(90)
        ->and((int) round(now()->diffInDays($this->a->accessEndsAt())))->toBe(30);

    // A's access ends: B keeps its own.
    $this->a->subscriptions()->update(['ends_at' => now()->subMinute()]);
    expect($this->a->hasAccess())->toBeFalse()->and($b->hasAccess())->toBeTrue();
});

it('asks which iPhone a code is for once there are several, without spending the code', function () {
    $this->codes->redeem($this->user, issueActivationCode(30), newDevice: true);
    connectIphone($this->user, IPHONE_B);
    $code = issueActivationCode(30);

    expect(fn () => $this->codes->redeem($this->user, $code))->toThrow(\App\Exceptions\ApiException::class);
    expect(ActivationCode::query()->where('code_hash', ActivationCodeService::hash(ActivationCodeService::normalize($code)))->value('status'))
        ->toBe(\App\Enums\ActivationCodeStatus::Issued);

    $this->codes->redeem($this->user, $code, $this->a);
    expect((int) round(now()->diffInDays($this->a->refresh()->accessEndsAt())))->toBe(60);
});

it('allows five iPhones on one account', function () {
    foreach (range(2, 5) as $_) {
        $this->codes->redeem($this->user, issueActivationCode(30), newDevice: true);
    }
    expect(fn () => $this->codes->redeem($this->user, issueActivationCode(30), newDevice: true))
        ->toThrow(\App\Exceptions\ApiException::class, 'максимальное число устройств');
});

it('takes a code for a new iPhone through the API and lists the devices with their access', function () {
    Sanctum::actingAs($this->user);
    $this->postJson('/api/v1/activation/redeem', ['code' => issueActivationCode(30), 'new_device' => true])
        ->assertCreated()
        ->assertJsonPath('data.device.connected', false)
        ->assertJsonPath('data.device.state', 'connect');

    $this->getJson('/api/v1/devices/me')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $this->a->public_id)
        ->assertJsonPath('data.0.connected', true)
        ->assertJsonPath('data.0.access.active', true)
        ->assertJsonPath('data.1.state', 'connect')
        ->assertJsonPath('meta.limit', 5)
        ->assertJsonPath('meta.can_add', true);

    // The site says which iPhone it runs on; the status follows that iPhone.
    $this->getJson('/api/v1/storefront/status', ['X-Storefront-Device' => $this->a->public_id])
        ->assertJsonPath('data.device.id', $this->a->public_id)
        ->assertJsonPath('data.pending_device', true);
});

it('gates installs and MAX/VK notifications per iPhone', function () {
    $b = $this->codes->redeem($this->user, issueActivationCode(30), newDevice: true)->device;
    connectIphone($this->user, IPHONE_B);
    $this->a->subscriptions()->update(['ends_at' => now()->subMinute()]);

    config(['storefront.mx_relay.secret' => 'relay-secret']);
    $verdicts = $this->postJson('/api/v1/mx-relay/entitlements', ['udids' => [IPHONE_A, IPHONE_B]], ['X-MX-Relay-Secret' => 'relay-secret'])
        ->assertOk()->json('results');
    expect($verdicts[IPHONE_A]['active'])->toBeFalse()
        ->and($verdicts[IPHONE_A]['reason'])->toBe('NO_SUBSCRIPTION')
        ->and($verdicts[IPHONE_B]['active'])->toBeTrue();
});

it('adds the waited Apple time to the iPhone that waited', function () {
    $b = $this->codes->redeem($this->user, issueActivationCode(30), newDevice: true)->device;
    $bDevice = connectIphone($this->user, IPHONE_B);
    expect($bDevice->id)->toBe($b->id)
        ->and(Subscription::query()->where('device_id', $b->id)->exists())->toBeTrue()
        ->and(Device::query()->where('udid_hash', app(UdidHasher::class)->hash(IPHONE_B))->value('id'))->toBe($b->id);
});
