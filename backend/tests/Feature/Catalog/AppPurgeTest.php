<?php

use App\Enums\ArtifactStatus;
use App\Enums\RoleSlug;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\SignedBuild;
use App\Services\Operations\RetentionService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IpaBuilder;

beforeEach(function () {
    Storage::fake('artifacts');
    connectFakeAppleTeam();
    approveTeamFor('com.example.demo');
    $this->manager = userWithRoles(RoleSlug::CatalogManager);
    $this->listing = CatalogApp::factory()->create(['visibility' => 'PUBLISHED']);

    // A published build and a device's signed copy of it.
    $this->artifact = inspected(uploadIpa($this->manager, $this->listing, IpaBuilder::app()->build()));
    asStaff($this->manager)->postJson("/api/v1/admin/artifacts/{$this->artifact->public_id}/review", [
        'decision' => 'approve',
        'checklist' => ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true],
        'acknowledge_scan_result' => true,
    ])->assertOk();
    asStaff($this->manager)->postJson("/api/v1/admin/artifacts/{$this->artifact->public_id}/publish")->assertOk();
    $this->build = SignedBuild::create(['artifact_id' => $this->artifact->id, 'device_id' => Device::factory()->create()->id]);
    $this->build->forceFill(['storage_path' => "signed/{$this->build->public_id}.ipa"])->save();
    Storage::disk('artifacts')->put($this->build->storage_path, 'signed ipa');
});

it('revokes the builds of a deleted app and deletes their files from disk', function () {
    $original = $this->artifact->refresh()->storage_path;
    expect(Storage::disk('artifacts')->exists($original))->toBeTrue();

    asStaff($this->manager)->deleteJson("/api/v1/admin/apps/{$this->listing->public_id}", ['reason' => 'No longer offered'])
        ->assertOk()
        ->assertJsonPath('data.purged.artifacts', 1)
        ->assertJsonPath('data.purged.signed_builds', 1);

    expect($this->artifact->refresh()->status)->toBe(ArtifactStatus::Revoked)
        ->and($this->artifact->purged_at)->not->toBeNull()
        ->and($this->build->refresh()->purged_at)->not->toBeNull()
        ->and(Storage::disk('artifacts')->exists($original))->toBeFalse()
        ->and(Storage::disk('artifacts')->exists($this->build->storage_path))->toBeFalse();
});

it('cleans up listings deleted before purging existed', function () {
    $this->listing->delete();

    app(RetentionService::class)->run();

    expect($this->artifact->refresh()->purged_at)->not->toBeNull()
        ->and(Storage::disk('artifacts')->exists((string) $this->artifact->storage_path))->toBeFalse();
});
