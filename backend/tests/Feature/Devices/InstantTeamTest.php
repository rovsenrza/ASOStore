<?php

use App\Enums\AppleDeviceStatus;
use App\Enums\AppleTeamStatus;
use App\Enums\ArtifactStatus;
use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Jobs\SyncDeviceRegistrationsJob;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\MembershipYear;
use App\Models\TeamQuota;
use App\Notifications\DeviceReadyNotification;
use App\Services\Apple\AppleDevice;
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

it('keeps a device Apple is processing on its team, however long it waits', function () {
    Notification::fake();
    config(['storefront.apple.instant_device_limit' => 0]);
    $this->apple->onRegister = fn ($udid) => new AppleDevice('APPLE-SLOW', $udid, AppleDeviceStatus::Processing);
    $waiting = ($this->enrol)(TEST_UDID);
    $this->apple->onGet = fn ($id) => new AppleDevice($id, TEST_UDID, AppleDeviceStatus::Processing);

    // A team with instant slots is free, but moving there would take a second paid slot.
    config(['storefront.apple.instant_device_limit' => 10]);
    $this->travel(3)->days();
    app()->call([new SyncDeviceRegistrationsJob, 'handle']);
    expect($waiting->device->registrations()->count())->toBe(1)
        ->and(array_filter($this->apple->calls, fn (string $call) => str_starts_with($call, 'register:')))->toHaveCount(1);

    $this->apple->onGet = fn ($id) => new AppleDevice($id, TEST_UDID, AppleDeviceStatus::Enabled);
    $this->travel(2)->minutes();
    app()->call([new SyncDeviceRegistrationsJob, 'handle']);
    expect($waiting->fresh()->status)->toBe(Status::Eligible);
    Notification::assertSentToTimes($waiting->device->user, DeviceReadyNotification::class, 1);
});

it('never registers a device with a second team in the same membership year', function () {
    $held = ($this->enrol)(TEST_UDID);
    expect($held)->status->toBe(Status::Eligible)->apple_team_id->toBe($this->primary->id);

    $second = DeviceRegistration::create([
        'device_id' => $held->device_id, 'apple_team_id' => $this->second->id, 'udid_hash' => $held->udid_hash,
        'membership_year_id' => $this->second->currentMembershipYear()->id, 'device_family' => DeviceFamily::Iphone, 'status' => Status::Enrolled,
    ]);
    $this->apple->calls = [];
    app(DeviceRegistrationService::class)->register($second);

    expect($second->fresh())->status->toBe(Status::AppleFailed)->status_reason->toBe('DEVICE_ON_OTHER_TEAM')
        ->and($this->apple->calls)->toBe([])
        ->and(TeamQuota::where('apple_team_id', $this->second->id)->get()->sum(fn (TeamQuota $quota) => $quota->registeredCount()))->toBe(0);
});
