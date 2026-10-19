<?php

use App\Models\Device;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Devices\UdidHasher;

beforeEach(function () {
    config(['storefront.mx_relay.secret' => 'topsecret']);
    $this->udid = '00008140-00145D163C69801C';
});

function makeDevice(string $udid): Device
{
    $user = User::factory()->create();
    $device = Device::factory()->make(['user_id' => $user->id]);
    $device->setUdid($udid);
    $device->save();

    return $device;
}

function grantSubscription(User $user, ?string $endsAt = '+1 month'): Subscription
{
    return Subscription::query()->create([
        'user_id' => $user->id,
        'activation_code_id' => null,
        'plan' => 'month1',
        'status' => 'ACTIVE',
        'starts_at' => now()->subDay(),
        'ends_at' => $endsAt === null ? null : now()->modify($endsAt),
    ]);
}

it('reports an active device as entitled', function () {
    $device = makeDevice($this->udid);
    grantSubscription($device->user);

    $this->withHeader('X-MX-Relay-Secret', 'topsecret')
        ->postJson('/api/v1/mx-relay/entitlement', ['udid' => $this->udid])
        ->assertOk()
        ->assertJson(['active' => true, 'reason' => null]);
});

it('matches the UDID case-insensitively (profiles are uppercase)', function () {
    $device = makeDevice($this->udid);
    grantSubscription($device->user);

    $this->withHeader('X-MX-Relay-Secret', 'topsecret')
        ->postJson('/api/v1/mx-relay/entitlement', ['udid' => strtolower($this->udid)])
        ->assertOk()
        ->assertJson(['active' => true]);
});

it('reports no subscription as not entitled', function () {
    makeDevice($this->udid);

    $this->withHeader('X-MX-Relay-Secret', 'topsecret')
        ->postJson('/api/v1/mx-relay/entitlement', ['udid' => $this->udid])
        ->assertOk()
        ->assertJson(['active' => false, 'reason' => 'NO_SUBSCRIPTION']);
});

it('reports an expired subscription as not entitled', function () {
    $device = makeDevice($this->udid);
    Subscription::query()->create([
        'user_id' => $device->user->id, 'activation_code_id' => null, 'plan' => 'month1',
        'status' => 'ACTIVE', 'starts_at' => now()->subMonths(2), 'ends_at' => now()->subDay(),
    ]);

    $this->withHeader('X-MX-Relay-Secret', 'topsecret')
        ->postJson('/api/v1/mx-relay/entitlement', ['udid' => $this->udid])
        ->assertOk()
        ->assertJson(['active' => false, 'reason' => 'NO_SUBSCRIPTION']);
});

it('reports an unknown device as not entitled', function () {
    $this->withHeader('X-MX-Relay-Secret', 'topsecret')
        ->postJson('/api/v1/mx-relay/entitlement', ['udid' => $this->udid])
        ->assertOk()
        ->assertJson(['active' => false, 'reason' => 'UNKNOWN_DEVICE']);
});

it('404s without the shared secret', function () {
    makeDevice($this->udid);

    $this->postJson('/api/v1/mx-relay/entitlement', ['udid' => $this->udid])->assertNotFound();
    $this->withHeader('X-MX-Relay-Secret', 'wrong')
        ->postJson('/api/v1/mx-relay/entitlement', ['udid' => $this->udid])->assertNotFound();
});

it('404s when no secret is configured', function () {
    config(['storefront.mx_relay.secret' => null]);
    makeDevice($this->udid);

    $this->withHeader('X-MX-Relay-Secret', '')
        ->postJson('/api/v1/mx-relay/entitlement', ['udid' => $this->udid])->assertNotFound();
});
