<?php

use App\Models\Device;
use App\Models\User;
use App\Services\Devices\UdidHasher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('encrypts the UDID at rest and indexes it by HMAC', function () {
    $device = Device::factory()->make();
    $device->setUdid('00008030-001a2b3c4d5e6f70');
    $device->save();

    $raw = DB::table('devices')->where('id', $device->id)->first();

    expect($raw->udid_encrypted)->not->toContain('00008030')
        ->and($raw->udid_hash)->toBe(app(UdidHasher::class)->hash('00008030-001A2B3C4D5E6F70'))
        ->and($device->fresh()->udid_encrypted)->toBe('00008030-001A2B3C4D5E6F70')
        ->and($device->maskedUdid())->toBe('••••-6F70');
});

it('never serializes the UDID', function () {
    $json = Device::factory()->create()->toJson();

    expect($json)->not->toContain('udid_encrypted')->not->toContain('udid_hash');
});

it('prevents registering the same device twice for one user', function () {
    $user = User::factory()->create();
    $first = Device::factory()->for($user)->make();
    $first->setUdid('00008030-AAAA');
    $first->save();

    $second = Device::factory()->for($user)->make();
    $second->setUdid(' 00008030-aaaa ');

    expect(fn () => $second->save())->toThrow(QueryException::class);
});
