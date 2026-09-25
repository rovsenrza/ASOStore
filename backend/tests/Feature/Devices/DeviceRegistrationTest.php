<?php

use App\Enums\AppleDeviceStatus;
use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Jobs\RegisterDeviceJob;
use App\Jobs\SyncDeviceRegistrationsJob;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Services\Apple\AppleDevice;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleRetryableException;
use App\Services\Devices\DeviceRegistrationService;
use Tests\Support\ScriptedApple;

beforeEach(function () {
    $this->apple = ScriptedApple::install();
    $this->team = connectFakeAppleTeam();
    $this->enrol = function (string $udid, DeviceFamily $family = DeviceFamily::Iphone): DeviceRegistration {
        $device = Device::factory()->make(['device_family' => $family]);
        $device->setUdid($udid);
        $device->enrolled_at = now();
        $device->save();

        return app(DeviceRegistrationService::class)->request($device);
    };
    $this->register = fn (DeviceRegistration $registration) => app(DeviceRegistrationService::class)->register($registration);
});

it('registers an enrolled device and marks it eligible', function () {
    $registration = ($this->enrol)(TEST_UDID);

    ($this->register)($registration);

    expect($registration->fresh())
        ->status->toBe(Status::Eligible)
        ->apple_device_id->toBe('APPLE-6F70')
        ->attempts->toBe(1)
        ->eligible_at->not->toBeNull()
        ->and($this->apple->calls)->toBe(['find:'.TEST_UDID, 'register:'.TEST_UDID]);
});

it('reuses a device Apple already knows instead of registering it twice', function () {
    $this->apple->known[TEST_UDID] = new AppleDevice('APPLE-EXISTING', TEST_UDID, AppleDeviceStatus::Enabled);
    $registration = ($this->enrol)(TEST_UDID);

    ($this->register)($registration);

    expect($registration->fresh()->apple_device_id)->toBe('APPLE-EXISTING')
        ->and($this->apple->calls)->toBe(['find:'.TEST_UDID]);
});

it('blocks at the per-family limit and never tries another team', function () {
    config(['storefront.apple.device_limit_per_family' => 2]);
    $first = ($this->enrol)('00008030-0000000000000001');
    $second = ($this->enrol)('00008030-0000000000000002');
    $third = ($this->enrol)('00008030-0000000000000003');
    $ipad = ($this->enrol)('00008030-0000000000000004', DeviceFamily::Ipad);

    foreach ([$first, $second, $third, $ipad] as $registration) {
        ($this->register)($registration);
    }

    // No other team is eligible, so nothing switches (FULL_PLAN §6.2).
    expect($third->fresh())
        ->status->toBe(Status::NoEligibleTeam)
        ->status_reason->toBe('NO_ELIGIBLE_TEAM')
        ->apple_team_id->toBe($this->team->id)
        // iPads are counted separately.
        ->and($ipad->fresh()->status)->toBe(Status::Eligible)
        ->and($this->apple->calls)->not->toContain('register:00008030-0000000000000003')
        ->and(AuditLog::where('action', 'device_registration.status_changed')->where('after->status', 'NO_ELIGIBLE_TEAM')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'quota.no_eligible_team')->exists())->toBeTrue();
});

it('frees nothing when a device is disabled: the slot stays used for the year', function () {
    config(['storefront.apple.device_limit_per_family' => 1]);
    $first = ($this->enrol)('00008030-0000000000000001');
    ($this->register)($first);
    $first->fresh()->forceFill(['status' => Status::Disabled])->save();

    $second = ($this->enrol)('00008030-0000000000000002');
    ($this->register)($second);

    expect($second->fresh()->status)->toBe(Status::NoEligibleTeam);
});

it('keeps the slot and retries later when Apple is unavailable', function () {
    $this->apple->onRegister = fn () => throw new AppleRetryableException('Rate limited', 120);
    $registration = ($this->enrol)(TEST_UDID);

    expect(fn () => ($this->register)($registration))->toThrow(AppleRetryableException::class);
    expect($registration->fresh())->status->toBe(Status::ApplePending)->status_reason->toBe('APPLE_UNAVAILABLE');

    // The job asks the queue to try again after Apple's Retry-After.
    $job = (new RegisterDeviceJob($registration->id))->withFakeQueueInteractions();
    $this->apple->onRegister = fn () => throw new AppleRetryableException('Rate limited', 120);
    $job->handle(app(DeviceRegistrationService::class));
    $job->assertReleased(120);

    $this->apple->onRegister = null;
    ($this->register)($registration);
    expect($registration->fresh()->status)->toBe(Status::Eligible);
});

it('fails permanently on a rejected request and frees the slot', function () {
    config(['storefront.apple.device_limit_per_family' => 1]);
    $this->apple->onRegister = fn () => throw new AppleException('Invalid UDID', 'APPLE_REQUEST_FAILED');
    $rejected = ($this->enrol)('00008030-0000000000000001');
    ($this->register)($rejected);

    expect($rejected->fresh())->status->toBe(Status::AppleFailed)->status_reason->toBe('APPLE_REQUEST_FAILED');

    $this->apple->onRegister = null;
    $next = ($this->enrol)('00008030-0000000000000002');
    ($this->register)($next);
    expect($next->fresh()->status)->toBe(Status::Eligible);
});

it('waits while Apple processes the device and finishes on the next sync', function () {
    $this->apple->onRegister = fn ($udid) => new AppleDevice('APPLE-NEW', $udid, AppleDeviceStatus::Processing);
    $registration = ($this->enrol)(TEST_UDID);
    ($this->register)($registration);
    expect($registration->fresh())->status->toBe(Status::ApplePending)->status_reason->toBe('APPLE_PROCESSING');

    $this->apple->onGet = fn ($id) => new AppleDevice($id, TEST_UDID, AppleDeviceStatus::Enabled);
    $this->travel(2)->minutes();
    app()->call([new SyncDeviceRegistrationsJob, 'handle']);

    expect($registration->fresh()->status)->toBe(Status::Eligible)
        ->and($this->apple->calls)->toContain('get:APPLE-NEW');
});

it('waits without calling Apple while the integration is not configured', function () {
    $this->apple->configured = false;
    $registration = ($this->enrol)(TEST_UDID);

    ($this->register)($registration);

    expect($registration->fresh())->status->toBe(Status::Enrolled)->status_reason->toBe('APPLE_NOT_CONNECTED')
        ->and($this->apple->calls)->toBe([]);
});

it('marks the registration failed once the job gives up', function () {
    $registration = ($this->enrol)(TEST_UDID);
    $registration->forceFill(['status' => Status::ApplePending])->save();

    (new RegisterDeviceJob($registration->id))->failed(new RuntimeException('gave up'));

    expect($registration->fresh())->status->toBe(Status::AppleFailed)->status_reason->toBe('APPLE_UNAVAILABLE');
});
