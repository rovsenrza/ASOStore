<?php

use App\Enums\ArtifactStatus;
use App\Enums\InstallationStatus;
use App\Enums\RoleSlug;
use App\Enums\SignedBuildStatus;
use App\Models\AppArtifact;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\Installation;
use App\Models\SignedBuild;
use App\Services\Artifacts\StorageJanitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IpaBuilder;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->temp = sys_get_temp_dir().'/janitor-temp-'.bin2hex(random_bytes(6));
    config(['storefront.build_storage.temp_path' => $this->temp, 'storefront.artifacts.file_cache_path' => $this->temp.'-cache']);
    $team = connectFakeAppleTeam();
    approveTeamFor('com.example.demo', $team);

    $manager = userWithRoles(RoleSlug::CatalogManager);
    $this->catalogApp = CatalogApp::factory()->create(['visibility' => 'PUBLISHED']);
    $this->artifact = inspected(uploadIpa($manager, $this->catalogApp, IpaBuilder::app()->build()));
    test()->postJson("/api/v1/admin/artifacts/{$this->artifact->public_id}/review", [
        'decision' => 'approve',
        'checklist' => ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true],
        'acknowledge_scan_result' => true,
    ])->assertOk();
    test()->postJson("/api/v1/admin/artifacts/{$this->artifact->public_id}/publish")->assertOk();
    forgetGuards();

    $this->customer = subscribedCustomer();
    postEnrollment(enrollmentChallenge($this->customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);
    forgetGuards();
    $this->device = Device::sole();
});

afterEach(function () {
    app('files')->deleteDirectory($this->temp);
    app('files')->deleteDirectory($this->temp.'-cache');
});

/** A deliverable build with a file, optionally with an installation in the given state. */
function storedBuild(object $test, ?InstallationStatus $installation = null, int $bytes = 1000): SignedBuild
{
    $build = SignedBuild::create(['artifact_id' => $test->artifact->id, 'device_id' => $test->device->id, 'last_used_at' => now()]);
    $path = "signed/{$build->public_id}.ipa";
    $content = Str::random($bytes);
    Storage::disk('artifacts')->put($path, $content);
    $build->forceFill(['status' => SignedBuildStatus::Deliverable, 'storage_path' => $path, 'sha256' => hash('sha256', $content), 'size_bytes' => $bytes])->save();
    if ($installation !== null) {
        Installation::create([
            'user_id' => $test->customer->id, 'device_id' => $test->device->id, 'app_id' => $test->catalogApp->id,
            'artifact_id' => $test->artifact->id, 'signed_build_id' => $build->id, 'status' => $installation,
        ]);
    }

    return $build;
}

function janitor(): StorageJanitor
{
    return app(StorageJanitor::class);
}

it('removes idle signed builds after the time their kind allows', function () {
    $warm = storedBuild($this);
    $delivered = storedBuild($this, InstallationStatus::Delivered);
    $ready = storedBuild($this, InstallationStatus::ReadyToInstall);

    $this->travel(13)->hours();
    janitor()->run();
    expect($warm->refresh()->status)->toBe(SignedBuildStatus::Expired)
        ->and($warm->status_reason)->toBe('IDLE')
        ->and($warm->purged_at)->not->toBeNull()
        ->and(Storage::disk('artifacts')->exists($warm->storage_path))->toBeFalse()
        ->and($delivered->refresh()->status)->toBe(SignedBuildStatus::Deliverable)
        ->and($ready->refresh()->status)->toBe(SignedBuildStatus::Deliverable);

    $this->travel(12)->hours();
    janitor()->run();
    expect($delivered->refresh()->status)->toBe(SignedBuildStatus::Expired)
        ->and(Storage::disk('artifacts')->exists($delivered->storage_path))->toBeFalse()
        ->and(Installation::where('signed_build_id', $delivered->id)->sole()->status)->toBe(InstallationStatus::Delivered)
        ->and($ready->refresh()->status)->toBe(SignedBuildStatus::Deliverable);

    $this->travel(48)->hours();
    $summary = janitor()->run();
    $waiting = Installation::where('signed_build_id', $ready->id)->sole();
    expect($ready->refresh()->status)->toBe(SignedBuildStatus::Expired)
        ->and($waiting->status)->toBe(InstallationStatus::Expired)
        ->and($waiting->status_reason)->toBe('BUILD_EXPIRED')
        ->and($waiting->events()->pluck('type')->all())->toContain('EXPIRED')
        ->and($summary['idle_builds'])->toBe(1)
        ->and(AuditLog::where('action', 'storage.janitor_applied')->count())->toBe(3)
        ->and(Cache::get(StorageJanitor::LAST_RUN)['summary']['builds_purged'])->toBe(1);
});

it('keeps a build that is being downloaded, and prepares a new one on the next tap after removal', function () {
    $ready = storedBuild($this, InstallationStatus::ReadyToInstall);
    $this->travel(73)->hours();
    $ready->refresh()->markUsed();
    janitor()->run();
    expect($ready->refresh()->status)->toBe(SignedBuildStatus::Deliverable);

    $this->travel(73)->hours();
    janitor()->run();
    expect($ready->refresh()->status)->toBe(SignedBuildStatus::Expired);

    // The catalog offers «Получить» again and the tap starts a fresh build.
    Queue::fake();
    Sanctum::actingAs($this->customer);
    $this->getJson("/api/v1/apps/{$this->catalogApp->public_id}")->assertJsonPath('data.install_state.status', 'get');
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202)->assertJsonPath('data.status', 'PREPARING');
    expect(SignedBuild::query()->where('status', SignedBuildStatus::SigningPending->value)->count())->toBe(1);
});

it('starts again when a waiting installation lost its build some other way', function () {
    $ready = storedBuild($this, InstallationStatus::ReadyToInstall);
    // E.g. a reclaim that ran before this fix, or a revoked certificate: the row was left waiting.
    DB::table('signed_builds')->where('id', $ready->id)->update(['status' => 'EXPIRED', 'purged_at' => now()]);

    Queue::fake();
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202)->assertJsonPath('data.status', 'PREPARING');
    expect(Installation::where('signed_build_id', $ready->id)->sole()->status)->toBe(InstallationStatus::Expired)
        ->and(Installation::count())->toBe(2);
});

it('removes the least recently used builds above the budget, unused ones first', function () {
    config(['storefront.build_storage.budget_bytes' => 2500]);
    $delivered = storedBuild($this, InstallationStatus::Delivered);
    $this->travel(5)->minutes();
    $ready = storedBuild($this, InstallationStatus::ReadyToInstall);
    $this->travel(5)->minutes();
    $warm = storedBuild($this);
    $this->travel(40)->minutes();
    $fresh = storedBuild($this);

    // 4000 bytes against 2500, down to 90 %: unused first, then installed. The fresh build
    // is still in its half-hour of use, and none is old enough to be idle yet.
    $summary = janitor()->run();
    expect($summary['budget_builds'])->toBe(2)
        ->and($warm->refresh()->status_reason)->toBe('STORAGE_BUDGET')
        ->and($delivered->refresh()->status)->toBe(SignedBuildStatus::Expired)
        ->and($ready->refresh()->status)->toBe(SignedBuildStatus::Deliverable)
        ->and($fresh->refresh()->status)->toBe(SignedBuildStatus::Deliverable)
        ->and(janitor()->signedBytes())->toBe(2000);
});

it('removes files that cannot be installed, objects nothing refers to and stale temporary copies', function () {
    $revoked = storedBuild($this);
    DB::table('signed_builds')->where('id', $revoked->id)->update(['status' => 'REVOKED']);
    $disk = Storage::disk('artifacts');
    $orphan = 'signed/'.strtolower((string) Str::ulid()).'.ipa';
    $disk->put($orphan, 'left by a crashed upload');
    $foreign = 'backups/keep-me.bin';
    $disk->put($foreign, 'not ours to judge');
    $abandoned = 'uploads/'.strtolower((string) Str::ulid()).'/chunks/0.part';
    $disk->put($abandoned, 'chunk');
    mkdir($this->temp, 0700, true);
    file_put_contents($this->temp.'/ipaOLD123', 'killed worker copy');
    file_put_contents($this->temp.'/ipaNEW456', 'running');

    $this->travel(3)->days();
    foreach ([$orphan, $foreign, $abandoned] as $path) {
        touch($disk->path($path), now()->subDays(3)->getTimestamp());
    }
    touch($this->temp.'/ipaOLD123', now()->subHours(7)->getTimestamp());
    touch($this->temp.'/ipaNEW456', now()->getTimestamp());

    $dry = janitor()->run(dryRun: true);
    expect($dry['orphan_objects'])->toBe(2)
        ->and($dry['builds_purged'])->toBe(1)
        ->and($disk->exists($orphan))->toBeTrue()
        ->and($revoked->refresh()->purged_at)->toBeNull();

    $summary = janitor()->run(sweepObjects: true);
    expect($summary['orphan_objects'])->toBe(2)
        ->and($summary['temp_files'])->toBe(1)
        ->and($disk->exists($orphan))->toBeFalse()
        ->and($disk->exists($abandoned))->toBeFalse()
        ->and($disk->exists($foreign))->toBeTrue()
        ->and($disk->exists($this->artifact->storage_path))->toBeTrue()
        ->and($revoked->refresh()->purged_at)->not->toBeNull()
        ->and($disk->exists($revoked->storage_path))->toBeFalse()
        ->and(is_file($this->temp.'/ipaOLD123'))->toBeFalse()
        ->and(is_file($this->temp.'/ipaNEW456'))->toBeTrue();
});

it('stops speculative builds at the per-device limit and near the budget', function () {
    expect(janitor()->allowsWarmup($this->device))->toBeTrue();

    config(['storefront.build_storage.warm_builds_per_device' => 1]);
    storedBuild($this);
    expect(janitor()->allowsWarmup($this->device))->toBeFalse();

    config(['storefront.build_storage.warm_builds_per_device' => 5, 'storefront.build_storage.budget_bytes' => 1200]);
    expect(janitor()->allowsWarmup($this->device))->toBeFalse();

    // A disk with less free space than required stops them too.
    config(['storefront.build_storage.budget_bytes' => 10 * 1024 ** 3, 'storefront.build_storage.min_free_disk_ratio' => 1.01]);
    expect(janitor()->allowsWarmup($this->device))->toBeFalse();
});

it('removes a superseded original after its rollback window, unless the file is shared', function () {
    $old = $this->artifact;
    DB::table('app_artifacts')->where('id', $old->id)->update(['status' => ArtifactStatus::Expired->value]);
    $twin = AppArtifact::factory()->create(['app_id' => $this->catalogApp->id, 'status' => ArtifactStatus::Expired->value, 'storage_path' => 'originals/ab/'.str_repeat('ab', 32).'.ipa']);
    Storage::disk('artifacts')->put($twin->storage_path, 'superseded');

    $this->travel(13)->days();
    expect(janitor()->run()['originals_purged'])->toBe(0);

    $this->travel(2)->days();
    janitor()->run();
    expect($twin->refresh()->purged_at)->not->toBeNull()
        ->and(Storage::disk('artifacts')->exists($twin->storage_path))->toBeFalse()
        ->and($old->refresh()->purged_at)->not->toBeNull()
        ->and(Storage::disk('artifacts')->exists($old->storage_path))->toBeFalse();
});

it('reports storage use to operators', function () {
    storedBuild($this);
    storedBuild($this, InstallationStatus::Delivered, 2000);
    janitor()->run();

    asStaff(userWithRoles(RoleSlug::Admin))->getJson('/api/v1/admin/storage')
        ->assertOk()
        ->assertJsonPath('data.signed_builds.bytes', 3000)
        ->assertJsonPath('data.signed_builds.by_kind.warm.count', 1)
        ->assertJsonPath('data.signed_builds.by_kind.delivered.bytes', 2000)
        ->assertJsonPath('data.policy.warm_idle_hours', 12)
        ->assertJsonStructure(['data' => ['originals' => ['count', 'bytes'], 'disk' => ['free_bytes', 'total_bytes'], 'cache', 'last_run' => ['at', 'summary']]]);

    forgetGuards();
    Sanctum::actingAs($this->customer);
    $this->getJson('/api/v1/admin/storage')->assertForbidden();
});
