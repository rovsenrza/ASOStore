<?php

use App\Enums\AppleDeviceStatus;
use App\Enums\AppleTeamStatus;
use App\Enums\ArtifactStatus;
use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Jobs\SyncDeviceRegistrationsJob;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\MembershipYear;
use App\Models\TeamQuota;
use App\Notifications\DeviceReadyNotification;
use App\Services\Apple\AppleDevice;
use App\Services\Apple\AppleException;
use App\Services\Devices\DeviceRegistrationService;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ScriptedApple;

// Apple enables only the first devices of a new membership at once and holds the rest for
// 24–72 hours, so new devices go to a team still under that count.
beforeEach(function () {
    config(['storefront.apple.instant_device_limit' => 2]);
    $this->apple = ScriptedApple::install();
    $this->primary = connectFakeAppleTeam();
    variantTeam($this->primary, 'com.bundle.app');
    $this->second = variantTeam(null, 'com.bundle2.app');

    $this->enrol = function (string $udid): DeviceRegistration {
        $device = Device::factory()->make(['device_family' => DeviceFamily::Iphone]);
        $device->setUdid($udid);
        $device->enrolled_at = now();
        $device->save();
        $registration = app(DeviceRegistrationService::class)->request($device);
        app(DeviceRegistrationService::class)->register($registration);

        return $registration->refresh();
    };
});

/** Binds a published Ru App Store variant to the team, creating a second team when none is given. */
function variantTeam(?AppleTeam $team, string $bundle): AppleTeam
{
    if ($team === null) {
        $team = AppleTeam::create(['apple_team_id' => 'TEAM000002', 'name' => 'Second', 'status' => AppleTeamStatus::Active, 'membership_expires_at' => now()->addYear()]);
        MembershipYear::create(['apple_team_id' => $team->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
    }
    $app = CatalogApp::factory()->create(['is_storefront' => true]);
    AppArtifact::factory()->for($app, 'app')->create(['status' => ArtifactStatus::Published, 'bundle_identifier' => $bundle]);
    $team->forceFill(['storefront_app_id' => $app->id])->save();
    approveTeamFor($bundle, $team);

    return $team;
}

it('sends a new device to the next team once the primary is past the instant limit', function () {
    ($this->enrol)('00008030-0000000000000001');
    ($this->enrol)('00008030-0000000000000002');

    $third = ($this->enrol)('00008030-0000000000000003');

    expect($third->apple_team_id)->toBe($this->second->id)
        ->and($third->status)->toBe(Status::Eligible);

    // The scheduled sync must not add a primary registration for a device on another team.
    app()->call([new SyncDeviceRegistrationsJob, 'handle']);
    expect(DeviceRegistration::where('device_id', $third->device_id)->count())->toBe(1)
        // Enrolling again keeps the device where it is.
        ->and(app(DeviceRegistrationService::class)->request($third->device)->id)->toBe($third->id);
});

it('counts devices Apple lists outside the Storefront', function () {
    TeamQuota::create([
        'apple_team_id' => $this->primary->id, 'membership_year_id' => $this->primary->currentMembershipYear()->id,
        'device_family' => 'IPAD', 'limit_count' => 100, 'apple_registered_count' => 2,
    ]);

    expect(($this->enrol)('00008030-0000000000000001')->apple_team_id)->toBe($this->second->id);
});

it('keeps the primary team when routing is off or every team is past the limit', function () {
    config(['storefront.apple.instant_device_limit' => 0]);
    foreach (range(1, 3) as $n) {
        expect(($this->enrol)('00008030-000000000000000'.$n)->apple_team_id)->toBe($this->primary->id);
    }

    config(['storefront.apple.instant_device_limit' => 3]);
    expect(($this->enrol)('00008030-0000000000000004')->apple_team_id)->toBe($this->second->id);
    expect(($this->enrol)('00008030-0000000000000005')->apple_team_id)->toBe($this->second->id);
    expect(($this->enrol)('00008030-0000000000000006')->apple_team_id)->toBe($this->second->id);
    expect(($this->enrol)('00008030-0000000000000007')->apple_team_id)->toBe($this->primary->id);
});

it('moves a device Apple keeps processing to an instant team and emails the owner once', function () {
    Notification::fake();
    config(['storefront.apple.instant_device_limit' => 0]);
    $this->apple->onRegister = fn ($udid) => new AppleDevice('APPLE-SLOW', $udid, AppleDeviceStatus::Processing);
    $waiting = ($this->enrol)(TEST_UDID);
    $this->apple->onGet = fn ($id) => new AppleDevice($id, TEST_UDID, AppleDeviceStatus::Processing);
    expect($waiting)->status->toBe(Status::ApplePending)->status_reason->toBe('APPLE_PROCESSING');

    // Instant devices also show PROCESSING for a few minutes: not moved yet.
    config(['storefront.apple.instant_device_limit' => 10]);
    $this->travel(10)->minutes();
    app()->call([new SyncDeviceRegistrationsJob, 'handle']);
    expect($waiting->device->registrations()->count())->toBe(1);

    // The second team has never seen the device.
    unset($this->apple->known[TEST_UDID]);
    $this->apple->onRegister = null;
    $this->travel(25)->minutes();
    app()->call([new SyncDeviceRegistrationsJob, 'handle']);

    $moved = $waiting->device->fresh()->latestRegistration;
    $owner = $waiting->device->user;
    expect($moved->apple_team_id)->toBe($this->second->id)
        ->and($moved->status)->toBe(Status::Eligible)
        // Apple counts the first registration for the year either way.
        ->and($waiting->fresh()->status)->toBe(Status::ApplePending)
        ->and(AuditLog::where('action', 'device_registration.moved')->count())->toBe(1);
    Notification::assertSentToTimes($owner, DeviceReadyNotification::class, 1);

    // Apple later finishes the first team: no second email, the device stays on the second team.
    $this->apple->onGet = fn ($id) => new AppleDevice($id, TEST_UDID, AppleDeviceStatus::Enabled);
    $this->travel(2)->minutes();
    app()->call([new SyncDeviceRegistrationsJob, 'handle']);
    expect($waiting->fresh()->status)->toBe(Status::Eligible)
        ->and($waiting->device->fresh()->latestRegistration->id)->toBe($moved->id);
    Notification::assertSentToTimes($owner, DeviceReadyNotification::class, 1);
});

it('leaves the device where it was when Apple refuses it on the new team', function () {
    config(['storefront.apple.instant_device_limit' => 0]);
    $this->apple->onRegister = fn ($udid) => new AppleDevice('APPLE-SLOW', $udid, AppleDeviceStatus::Processing);
    $waiting = ($this->enrol)(TEST_UDID);

    config(['storefront.apple.instant_device_limit' => 10]);
    unset($this->apple->known[TEST_UDID]);
    $this->apple->onRegister = fn () => throw new AppleException('Device rejected', 'APPLE_REQUEST_FAILED');
    $this->artisan('devices:move-waiting')->assertFailed();

    expect($waiting->device->registrations()->count())->toBe(1)
        ->and($waiting->fresh()->status)->toBe(Status::ApplePending);
});

it('lists the moves without making them on a dry run', function () {
    config(['storefront.apple.instant_device_limit' => 0]);
    $this->apple->onRegister = fn ($udid) => new AppleDevice('APPLE-SLOW', $udid, AppleDeviceStatus::Processing);
    $waiting = ($this->enrol)(TEST_UDID);
    config(['storefront.apple.instant_device_limit' => 10]);

    $this->artisan('devices:move-waiting --dry-run')
        ->expectsOutputToContain('TEAM000002')
        ->assertSuccessful();
    expect($waiting->device->registrations()->count())->toBe(1);
});
