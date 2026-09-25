<?php

use App\Models\Device;
use App\Models\RefreshToken;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    connectFakeAppleTeam();
    $this->customer = subscribedCustomer();
    postEnrollment(enrollmentChallenge($this->customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);
    forgetGuards();
    $this->device = Device::sole();
});

it('binds the native app to the enrolled device with a one-time code', function () {
    Sanctum::actingAs($this->customer);
    $claim = $this->postJson('/api/v1/storefront/claims')->assertCreated()->json('data');
    expect($claim['url'])->toBe('storefront://claim?code='.$claim['code']);
    forgetGuards();

    $tokens = $this->postJson('/api/v1/storefront/claims/redeem', ['code' => $claim['code'], 'device_name' => 'iPhone'])
        ->assertCreated()
        ->assertJsonPath('data.user.id', $this->customer->public_id)
        ->assertJsonPath('data.device.id', $this->device->public_id)
        ->json('data');

    expect(RefreshToken::sole()->device_id)->toBe($this->device->id)
        ->and($this->device->fresh()->storefront_claimed_at)->not->toBeNull();

    $this->withToken($tokens['access_token'])->getJson('/api/v1/storefront/status')
        ->assertJsonPath('data.stage', 'storefront_installed')
        ->assertJsonPath('data.next_action', 'open_storefront');

    // Device binding survives a refresh.
    $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk();
    expect(RefreshToken::query()->whereNull('used_at')->sole()->device_id)->toBe($this->device->id);
});

it('accepts each claim code once and only for a short time', function () {
    Sanctum::actingAs($this->customer);
    $used = $this->postJson('/api/v1/storefront/claims')->json('data.code');
    $stale = $this->postJson('/api/v1/storefront/claims')->json('data.code');
    forgetGuards();

    $this->postJson('/api/v1/storefront/claims/redeem', ['code' => $used])->assertCreated();
    $this->postJson('/api/v1/storefront/claims/redeem', ['code' => $used])->assertUnprocessable()->assertJsonPath('error.code', 'CLAIM_INVALID');

    $this->travel(11)->minutes();
    $this->postJson('/api/v1/storefront/claims/redeem', ['code' => $stale])->assertJsonPath('error.code', 'CLAIM_INVALID');
});

it('issues claims only for devices registered with Apple', function () {
    $pending = subscribedCustomer();
    Sanctum::actingAs($pending);

    $this->postJson('/api/v1/storefront/claims')->assertForbidden()->assertJsonPath('error.code', 'DEVICE_NOT_ELIGIBLE');
});

it('lists the customer devices without the UDID', function () {
    Sanctum::actingAs($this->customer);

    $response = $this->getJson('/api/v1/devices/me')
        ->assertOk()
        ->assertJsonPath('data.0.udid_hint', '••••-6F70')
        ->assertJsonPath('data.0.registration.status', 'ELIGIBLE');

    expect($response->getContent())->not->toContain('4D5E6F70');
});
