<?php

use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\QuotaReservation;
use App\Services\Devices\DeviceRegistrationService;
use App\Services\Quotas\QuotaService;
use Illuminate\Support\Facades\Process;

/*
 * Phase 7 exit gate: 20 parallel reservations against 1 remaining slot. Runs
 * real PHP processes against MySQL, so data is committed (DatabaseMigrations).
 */
it('gives the last free slot to exactly one of 20 parallel registrations', function () {
    config(['storefront.apple.device_limit_per_family' => 3]);
    connectFakeAppleTeam();
    $service = app(DeviceRegistrationService::class);

    $register = function (int $index) use ($service): DeviceRegistration {
        $device = Device::factory()->make(['device_family' => DeviceFamily::Iphone]);
        $device->setUdid(sprintf('00008030-%016X', $index));
        $device->save();

        return $service->request($device);
    };

    // Two of three slots are already used.
    foreach ([1, 2] as $index) {
        expect(app(QuotaService::class)->reserve($register($index)))->toBe(QuotaService::RESERVED);
    }

    $contenders = collect(range(100, 119))->map($register);
    $startAt = microtime(true) + 3;

    $results = Process::pool(function ($pool) use ($contenders, $startAt) {
        foreach ($contenders as $registration) {
            $pool->path(base_path())->env([
                'STOREFRONT_APPLE_DEVICE_LIMIT' => '3',
            ])->command([PHP_BINARY, 'tests/Support/reserve-slot.php', (string) $registration->id, (string) $startAt]);
        }
    })->start()->wait();

    $outcomes = $results->collect()->map(fn ($result) => trim($result->output()) ?: 'ERROR: '.$result->errorOutput())->countBy()->all();

    expect($outcomes)->toEqual([QuotaService::RESERVED => 1, QuotaService::NO_ELIGIBLE_TEAM => 19])
        ->and(DeviceRegistration::query()->whereIn('status', DeviceRegistration::CONSUMING_STATUSES)->count())->toBe(3)
        ->and(DeviceRegistration::query()->where('status', Status::NoEligibleTeam->value)->count())->toBe(19)
        ->and(QuotaReservation::query()->count())->toBe(3)
        ->and(app(QuotaService::class)->quotaFor($contenders->first())->remaining())->toBe(0);
});
