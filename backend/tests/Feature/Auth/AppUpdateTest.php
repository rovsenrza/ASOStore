<?php

use App\Enums\ArtifactStatus;
use App\Enums\DeviceFamily;
use App\Enums\DeviceRegistrationStatus;
use App\Enums\RoleSlug;
use App\Models\AppArtifact;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Services\Auth\TokenService;

beforeEach(function () {
    $this->team = connectFakeAppleTeam();

    $app = CatalogApp::factory()->create(['is_storefront' => true]);
    AppArtifact::factory()->for($app, 'app')->create([
        'status' => ArtifactStatus::Published,
        'version' => '2.3.0',
        'build_number' => 42,
    ]);
    $this->team->forceFill(['storefront_app_id' => $app->id])->save();

    $this->user = userWithRoles(RoleSlug::Customer);
    $this->device = Device::factory()->create(['user_id' => $this->user->id]);
    DeviceRegistration::create([
        'device_id' => $this->device->id,
        'apple_team_id' => $this->team->id,
        'membership_year_id' => $this->team->currentMembershipYear()->id,
        'udid_hash' => str_repeat('a', 64),
        'device_family' => DeviceFamily::Iphone,
        'status' => DeviceRegistrationStatus::Eligible,
    ]);

    $this->pair = app(TokenService::class)->issue($this->user, 'iPhone', null, device: $this->device);
});

it('reports a newer build for this device\'s enrolled team', function () {
    $this->withToken($this->pair->accessToken)
        ->withHeader('X-App-Build', '40')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.app_update.build_number', 42)
        ->assertJsonPath('data.app_update.version', '2.3.0');
});

it('reports no update when already on the latest build', function () {
    $this->withToken($this->pair->accessToken)
        ->withHeader('X-App-Build', '42')
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.app_update', null);
});

it('reports no update when the header is absent', function () {
    $this->withToken($this->pair->accessToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.app_update', null);
});
