<?php

use App\Enums\AppleTeamStatus;
use App\Enums\DeviceFamily;
use App\Enums\RoleSlug;
use App\Enums\SubscriptionStatus;
use App\Enums\ArtifactStatus;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\MembershipYear;
use App\Models\Subscription;
use App\Models\TeamQuota;
use App\Models\User;
use App\Services\Devices\DeviceRegistrationService;
use Tests\Support\ScriptedApple;

// A warm-up team (routing_rule NEW_MONTHLY) takes only the first device of a new one-month
// customer, and leaves routing once its quota (limit_count 1) is used.
function warmVariant(AppleTeam $team, string $bundle): void
{
    $app = CatalogApp::factory()->create(['is_storefront' => true]);
    AppArtifact::factory()->for($app, 'app')->create(['status' => ArtifactStatus::Published, 'bundle_identifier' => $bundle]);
    $team->forceFill(['storefront_app_id' => $app->id])->save();
    approveTeamFor($bundle, $team);
}

beforeEach(function () {
    ScriptedApple::install();
    $this->primary = connectFakeAppleTeam();
    warmVariant($this->primary, 'com.bundle.app');
    // The primary is busy, so a normal team would win any tie.
    TeamQuota::create(['apple_team_id' => $this->primary->id, 'membership_year_id' => $this->primary->currentMembershipYear()->id, 'device_family' => 'IPHONE', 'limit_count' => 100, 'apple_registered_count' => 40]);

    $this->warm = AppleTeam::create(['apple_team_id' => 'WARM000001', 'name' => 'Warm', 'status' => AppleTeamStatus::Active, 'routing_rule' => AppleTeam::RULE_NEW_MONTHLY, 'membership_expires_at' => now()->addYear()]);
    $year = MembershipYear::create(['apple_team_id' => $this->warm->id, 'starts_at' => now()->subDay(), 'ends_at' => now()->addYear()]);
    TeamQuota::create(['apple_team_id' => $this->warm->id, 'membership_year_id' => $year->id, 'device_family' => 'IPHONE', 'limit_count' => 1]);
    TeamQuota::create(['apple_team_id' => $this->warm->id, 'membership_year_id' => $year->id, 'device_family' => 'IPAD', 'limit_count' => 0]);
    warmVariant($this->warm, 'com.warm.app');

    $this->customer = function (int $days): User {
        $user = userWithRoles(RoleSlug::Customer);
        Subscription::create(['user_id' => $user->id, 'plan' => 'standard', 'status' => SubscriptionStatus::Active, 'starts_at' => now(), 'ends_at' => now()->addDays($days)]);

        return $user;
    };
    $this->enrol = function (User $user, string $udid): DeviceRegistration {
        $device = connectIphone($user, $udid);
        $registration = app(DeviceRegistrationService::class)->request($device);
        app(DeviceRegistrationService::class)->register($registration);

        return $registration->refresh();
    };
});

it('gives the warm-up team one device of a new one-month customer, then routes as usual', function () {
    expect(($this->enrol)(($this->customer)(30), '00008030-0000000000000001')->apple_team_id)->toBe($this->warm->id)
        // Its one slot is used: the next new monthly customer goes elsewhere.
        ->and(($this->enrol)(($this->customer)(30), '00008030-0000000000000002')->apple_team_id)->toBe($this->primary->id);
});

it('never routes longer plans or a second device of the account to a warm-up team', function () {
    expect(($this->enrol)(($this->customer)(90), '00008030-0000000000000001')->apple_team_id)->toBe($this->primary->id);

    $user = ($this->customer)(30);
    $quota = TeamQuota::where(['apple_team_id' => $this->warm->id, 'device_family' => 'IPHONE'])->sole();
    $quota->forceFill(['limit_count' => 0])->save();
    expect(($this->enrol)($user, '00008030-0000000000000002')->apple_team_id)->toBe($this->primary->id);
    // The warm-up slot is free again, but the account already has a registered device.
    $quota->forceFill(['limit_count' => 1])->save();
    expect(($this->enrol)($user, '00008030-0000000000000003')->apple_team_id)->toBe($this->primary->id);
});
