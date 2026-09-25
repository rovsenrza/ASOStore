<?php

use App\Enums\AppleTeamStatus;
use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Enums\RoleSlug;
use App\Models\AppleTeam;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\Certificate;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\MembershipYear;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Devices\DeviceRegistrationService;
use App\Services\Quotas\QuotaReconciler;
use Laravel\Sanctum\Sanctum;
use Tests\Support\OpenApiContract;
use Tests\Support\ScriptedApple;

beforeEach(function () {
    config(['storefront.apple.device_limit_per_family' => 1]);
    $this->apple = ScriptedApple::install();
    $this->primary = connectFakeAppleTeam();
    $this->admin = userWithRoles(RoleSlug::Admin);

    $this->enrol = function (string $udid, ?User $owner = null): DeviceRegistration {
        $device = Device::factory()->make(['device_family' => DeviceFamily::Iphone, 'user_id' => ($owner ?? User::factory()->create())->id]);
        $device->setUdid($udid);
        $device->enrolled_at = now();
        $device->save();
        $registration = app(DeviceRegistrationService::class)->request($device);
        app(DeviceRegistrationService::class)->register($registration);

        return $registration->refresh();
    };
});

function secondTeam(string $teamId = 'TEAM000002'): AppleTeam
{
    $team = AppleTeam::create(['apple_team_id' => $teamId, 'name' => 'Second', 'status' => AppleTeamStatus::Active, 'membership_expires_at' => now()->addYear()]);
    MembershipYear::create(['apple_team_id' => $team->id, 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonths(11)]);

    return $team;
}

it('blocks without switching teams when no other team is eligible, and says so everywhere', function () {
    ($this->enrol)('00008030-0000000000000001');
    $customer = subscribedCustomer();
    $blocked = ($this->enrol)('00008030-0000000000000002', $customer);

    // An active team without eligibility for the Storefront is not a candidate.
    secondTeam();

    expect($blocked->status)->toBe(Status::NoEligibleTeam)
        ->and($blocked->apple_team_id)->toBe($this->primary->id)
        ->and(DeviceRegistration::count())->toBe(2)
        ->and(TeamAssignment::count())->toBe(0)
        ->and(AuditLog::where('action', 'quota.no_eligible_team')->sole()->reason)->toContain('nothing was switched')
        ->and($this->apple->calls)->not->toContain('register:00008030-0000000000000002');

    // Portal and app read the same blocking state.
    Sanctum::actingAs($customer);
    $this->getJson('/api/v1/storefront/status')
        ->assertJsonPath('data.stage', 'blocked')
        ->assertJsonPath('data.blocking_reason', 'NO_ELIGIBLE_TEAM');

    // Admin sees it on the device.
    forgetGuards();
    asStaff($this->admin)->getJson('/api/v1/admin/devices/'.$blocked->device->public_id)
        ->assertJsonPath('data.registration.status', 'NO_ELIGIBLE_TEAM');
});

it('proposes an eligible team, waits for admin approval, and registers only after it', function () {
    $storefront = CatalogApp::factory()->create(['is_storefront' => true]);
    ($this->enrol)('00008030-0000000000000001');
    $team = secondTeam();
    approveTeamFor('com.example.storefront', $team);
    // Without a published Storefront artifact, any approved eligibility makes the team a candidate.
    forgetGuards();

    $blocked = ($this->enrol)('00008030-0000000000000002');
    expect($blocked->status)->toBe(Status::QuotaBlocked)
        ->and($blocked->status_reason)->toBe('AWAITING_TEAM_APPROVAL');

    $assignment = TeamAssignment::sole();
    expect($assignment->status)->toBe('PENDING')
        ->and($assignment->apple_team_id)->toBe($team->id)
        ->and($assignment->selection_reason)->toContain('TEAM000002')
        ->and(DeviceRegistration::where('apple_team_id', $team->id)->count())->toBe(0);

    asStaff($this->admin)->getJson('/api/v1/admin/quota-assignments')
        ->assertOk()
        ->assertJsonPath('data.0.to_team.apple_team_id', 'TEAM000002')
        ->assertJsonPath('data.0.from_team', $this->primary->apple_team_id);

    $this->postJson("/api/v1/admin/quota-assignments/{$assignment->public_id}/approve")->assertUnprocessable();
    $this->postJson("/api/v1/admin/quota-assignments/{$assignment->public_id}/approve", ['reason' => 'Partner agreement #12 covers this device'])
        ->assertOk()
        ->assertJsonPath('data.status', 'APPROVED');

    $moved = DeviceRegistration::where('apple_team_id', $team->id)->sole();
    expect($moved->status)->toBe(Status::Eligible)
        ->and($moved->device_id)->toBe($blocked->device_id)
        ->and($blocked->device->latestRegistration->is($moved))->toBeTrue()
        ->and(AuditLog::where('action', 'team.assignment.approved')->sole()->reason)->toBe('Partner agreement #12 covers this device');

    // A decision is final.
    $this->postJson("/api/v1/admin/quota-assignments/{$assignment->public_id}/reject", ['reason' => 'late'])->assertStatus(409);
    expect($storefront->exists)->toBeTrue();
});

it('refuses an approval when the proposed team filled up meanwhile', function () {
    ($this->enrol)('00008030-0000000000000001');
    $team = secondTeam();
    approveTeamFor('com.example.storefront', $team);
    forgetGuards();
    ($this->enrol)('00008030-0000000000000002');
    $assignment = TeamAssignment::sole();

    // Someone else takes the second team's only slot.
    $year = $team->currentMembershipYear();
    DeviceRegistration::create([
        'device_id' => Device::factory()->create()->id, 'apple_team_id' => $team->id, 'membership_year_id' => $year->id,
        'udid_hash' => str_repeat('a', 64), 'device_family' => 'IPHONE', 'status' => Status::Eligible,
    ]);

    asStaff($this->admin)->postJson("/api/v1/admin/quota-assignments/{$assignment->public_id}/approve", ['reason' => 'ok'])
        ->assertJsonPath('error.code', 'NO_ELIGIBLE_TEAM');
    expect($assignment->refresh()->status)->toBe('PENDING');
});

it('reconciles counters with Apple and alerts on mismatches and expiries without correcting anything', function () {
    ($this->enrol)('00008030-0000000000000001');
    $this->apple->deviceCounts = ['IPHONE' => 3, 'IPAD' => 0];
    $this->primary->forceFill(['membership_expires_at' => now()->addDays(10)])->save();
    Certificate::create([
        'apple_team_id' => $this->primary->id, 'sha1_fingerprint' => str_repeat('B', 40), 'serial_number' => '1',
        'common_name' => 'Apple Distribution', 'expires_at' => now()->addDays(5),
    ]);

    $summary = app(QuotaReconciler::class)->run();
    $again = app(QuotaReconciler::class)->run();

    expect($summary)->toBe(['teams' => 1, 'mismatches' => 1, 'alerts' => 2])
        ->and($again['alerts'])->toBe(0) // once per day
        ->and($this->primary->refresh()->status)->toBe(AppleTeamStatus::Expiring)
        ->and(AuditLog::where('action', 'quota.mismatch')->first()->after)->toMatchArray(['family' => 'IPHONE', 'local' => 1, 'apple' => 3])
        ->and(DeviceRegistration::count())->toBe(1);

    $teams = asStaff($this->admin)->getJson('/api/v1/admin/apple-teams');
    expect(OpenApiContract::errors($teams->getContent(), 'AppleTeamListResponse'))->toBe([]);
    $teams->assertOk()
        ->assertJsonPath('data.0.quotas.0.family', 'IPAD')
        ->assertJsonPath('data.0.quotas.1.registered', 1)
        ->assertJsonPath('data.0.quotas.1.apple_registered', 3)
        ->assertJsonPath('data.0.quotas.1.remaining', 0);
});

it('onboards a team through verification before it can be activated', function () {
    asStaff($this->admin);
    $team = $this->postJson('/api/v1/admin/apple-teams', ['apple_team_id' => 'NEWTEAM001', 'name' => 'Partner'])
        ->assertCreated()
        ->assertJsonPath('data.status', 'PENDING_VERIFICATION')
        ->json('data');

    $this->patchJson("/api/v1/admin/apple-teams/{$team['id']}", ['status' => 'ACTIVE', 'reason' => 'go'])->assertStatus(409);

    $this->postJson("/api/v1/admin/apple-teams/{$team['id']}/credentials", [
        'issuer_id' => 'issuer', 'key_id' => 'ABCDE12345', 'vault_reference' => 'encrypted-file:secrets/apple/ABCDE12345.p8.enc',
    ])->assertCreated()->assertJsonPath('data.credential.key_id', 'ABCDE12345');
    $this->postJson("/api/v1/admin/apple-teams/{$team['id']}/verify")->assertOk();
    $this->postJson("/api/v1/admin/apple-teams/{$team['id']}/membership-years", ['starts_at' => '2026-10-01', 'ends_at' => '2027-10-01'])->assertCreated();
    $this->postJson("/api/v1/admin/apple-teams/{$team['id']}/membership-years", ['starts_at' => '2027-01-01', 'ends_at' => '2028-01-01'])->assertStatus(409);
    $this->patchJson("/api/v1/admin/apple-teams/{$team['id']}", ['status' => 'ACTIVE', 'reason' => 'Verified with owner'])
        ->assertOk()
        ->assertJsonPath('data.status', 'ACTIVE');

    expect(AuditLog::whereIn('action', ['apple_team.created', 'apple_team.credential_replaced', 'apple_team.verified', 'apple_team.membership_year_added', 'apple_team.updated'])->count())->toBe(5)
        ->and(json_encode(AuditLog::all()))->not->toContain('secrets/apple');
});

it('lets only admins manage teams', function () {
    asStaff(userWithRoles(RoleSlug::CatalogManager))->getJson('/api/v1/admin/apple-teams')->assertForbidden();
    forgetGuards();
    asStaff(userWithRoles(RoleSlug::Support))->postJson('/api/v1/admin/team-eligibilities', [])->assertForbidden();
});
