<?php

use App\Enums\ArtifactStatus;
use App\Enums\RoleSlug;
use App\Enums\SignedBuildStatus;
use App\Models\AppArtifact;
use App\Models\AppCategory;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\SignedBuild;
use App\Services\Signing\KeptBundleIds;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IpaBuilder;

/*
| catalog:device-test is what the Mac-side device-test agent calls over SSH: it lists the
| listings to test, signs builds for the test iPhone, makes cleaned copies without publishing
| them, and publishes or withdraws a copy once the device has judged it.
*/

beforeEach(function () {
    Storage::fake('artifacts');
    Storage::disk('artifacts')->buildTemporaryUrlsUsing(fn (string $path) => 'https://s3.test/'.$path);
    $this->keepFile = sys_get_temp_dir().'/keep-ids-'.bin2hex(random_bytes(4)).'.txt';
    // A scanner that reports every file clean: copies are approved only after a clean scan.
    $this->scanner = tempnam(sys_get_temp_dir(), 'clamd');
    file_put_contents($this->scanner, "#!/bin/sh\nexit 0\n");
    chmod($this->scanner, 0755);
    config([
        'storefront.inspection.clamdscan_path' => $this->scanner,
        'storefront.ipa_cleaner.enabled' => true,
        'storefront.signing.compat_shim.keep_bundle_ids' => [],
        'storefront.signing.compat_shim.keep_bundle_ids_file' => $this->keepFile,
    ]);
    $team = connectFakeAppleTeam();
    approveTeamFor('com.example.demo', $team);

    $this->manager = userWithRoles(RoleSlug::CatalogManager);
    $this->catalogApp = CatalogApp::factory()->create([
        'visibility' => 'PUBLISHED',
        'category_id' => AppCategory::factory()->create(['slug' => 'games-arcade'])->id,
    ]);
    $this->customer = subscribedCustomer();
    postEnrollment(enrollmentChallenge($this->customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);
    forgetGuards();
    $this->device = Device::sole();
});

afterEach(function () {
    @unlink($this->keepFile);
    @unlink($this->scanner);
});

/** Runs one action and returns its JSON line. */
function deviceTest(string $action, array $arguments = []): array
{
    $status = Artisan::call('catalog:device-test', ['action' => $action, '--user' => test()->manager->id, '--device' => test()->device->id] + $arguments);
    $result = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);
    expect($status === 0)->toBe($result['ok']);

    return $result;
}

function publishLive(object $test, string $ipa): AppArtifact
{
    $artifact = inspected(uploadIpa($test->manager, $test->catalogApp, $ipa));
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/review", [
        'decision' => 'approve',
        'checklist' => ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true],
        'acknowledge_scan_result' => true,
    ])->assertOk();
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/publish")->assertOk();
    forgetGuards();

    return $artifact->refresh();
}

function deliverableBuild(AppArtifact $artifact, Device $device): SignedBuild
{
    $build = SignedBuild::create(['artifact_id' => $artifact->id, 'device_id' => $device->id, 'last_used_at' => now()->subHour()]);
    Storage::disk('artifacts')->put("signed/{$build->public_id}.ipa", 'signed');
    $build->forceFill(['status' => SignedBuildStatus::Deliverable, 'storage_path' => "signed/{$build->public_id}.ipa", 'size_bytes' => 6])->save();

    return $build;
}

/** A game whose build carries an injected promotional dylib, like the supplied IPAs. */
function injectedGame(): string
{
    return IpaBuilder::app()
        ->executable(IpaBuilder::machO(loads: ['@executable_path/libobjcpatch.dylib']))
        ->withAppFile('libobjcpatch.dylib', IpaBuilder::machO().'https://t.me/promochannel RSPill')
        ->build();
}

it('lists the published listings of the asked categories', function () {
    $live = publishLive($this, IpaBuilder::app()->build());
    CatalogApp::factory()->create(['visibility' => 'PUBLISHED']);

    $result = deviceTest('queue', ['--category' => ['games-arcade']]);

    expect($result['apps'])->toHaveCount(1)
        ->and($result['apps'][0])->toMatchArray(['app_id' => $this->catalogApp->id, 'artifact_id' => $live->id, 'bundle_identifier' => 'com.example.demo', 'kept_bundle_id' => false]);
});

it('signs a build for the test device and hands out a download link once it is deliverable', function () {
    $live = publishLive($this, IpaBuilder::app()->build());
    Queue::fake();

    $signing = deviceTest('sign', ['target' => $live->id]);
    $build = SignedBuild::findOrFail($signing['build_id']);
    expect($signing['status'])->toBe('SIGNING_PENDING')
        ->and(deviceTest('build', ['target' => $build->id]))->not->toHaveKey('url');

    $build->forceFill(['status' => SignedBuildStatus::Deliverable, 'storage_path' => "signed/{$build->public_id}.ipa", 'size_bytes' => 6])->save();
    $ready = deviceTest('build', ['target' => $build->id]);

    expect($ready['url'])->toBe("https://s3.test/signed/{$build->public_id}.ipa")
        ->and($ready['bundle_identifier'])->toBe($live->signingBundleIdentifier());
});

it('makes a cleaned copy without publishing it, then publishes it when the device passed it', function () {
    if (! Process::run(['python3', '--version'])->successful()) {
        $this->markTestSkipped('python3 is needed for tools/ipa-cleaner.');
    }
    $live = publishLive($this, injectedGame());

    $candidate = deviceTest('candidate', ['target' => $this->catalogApp->id]);
    $copy = AppArtifact::findOrFail($candidate['artifact_id']);

    expect($candidate['result'])->toBe('READY')
        ->and($candidate['removed'])->toBe(['libobjcpatch.dylib'])
        ->and($copy->status)->toBe(ArtifactStatus::Ready)
        ->and($copy->derived_from_artifact_id)->toBe($live->id)
        ->and($live->refresh()->status)->toBe(ArtifactStatus::Published)
        // Asking again resumes the same copy instead of cleaning twice.
        ->and(deviceTest('candidate', ['target' => $this->catalogApp->id])['artifact_id'])->toBe($copy->id);

    $published = deviceTest('publish', ['target' => $copy->id]);

    expect($published['status'])->toBe('PUBLISHED')
        ->and($live->refresh()->status)->toBe(ArtifactStatus::Expired)
        ->and(AuditLog::where('action', 'catalog.device_test_replaced')->count())->toBe(1);
});

it('reports a listing without injected modules as having nothing to clean', function () {
    if (! Process::run(['python3', '--version'])->successful()) {
        $this->markTestSkipped('python3 is needed for tools/ipa-cleaner.');
    }
    publishLive($this, IpaBuilder::app()->build());

    expect(deviceTest('candidate', ['target' => $this->catalogApp->id])['result'])->toBe('NOTHING_TO_REMOVE')
        ->and(AppArtifact::whereNotNull('derived_from_artifact_id')->count())->toBe(0);
});

it('withdraws a copy that failed and never replaces a newer live build', function () {
    $live = publishLive($this, IpaBuilder::app()->build());
    $copy = AppArtifact::factory()->create(['app_id' => $this->catalogApp->id, 'derived_from_artifact_id' => $live->id, 'status' => ArtifactStatus::Ready]);
    $older = AppArtifact::factory()->create(['app_id' => $this->catalogApp->id, 'status' => ArtifactStatus::Expired]);
    $stale = AppArtifact::factory()->create(['app_id' => $this->catalogApp->id, 'derived_from_artifact_id' => $older->id, 'status' => ArtifactStatus::Ready]);

    $refused = deviceTest('publish', ['target' => $stale->id]);
    $discarded = deviceTest('discard', ['target' => $copy->id]);

    expect($refused['ok'])->toBeFalse()
        ->and($refused['error'])->toContain('no longer the one')
        ->and($stale->refresh()->status)->toBe(ArtifactStatus::Ready)
        ->and($discarded['status'])->toBe('REVOKED')
        ->and($live->refresh()->status)->toBe(ArtifactStatus::Published)
        ->and(deviceTest('publish', ['target' => $live->id])['error'])->toContain('not a cleaned copy');
});

it('keeps the original bundle ID through the file list and retires builds signed the old way', function () {
    $live = publishLive($this, IpaBuilder::app()->build());
    $build = deliverableBuild($live, $this->device);

    $kept = deviceTest('keep', ['target' => $this->catalogApp->id]);

    expect($kept)->toMatchArray(['bundle_identifiers' => ['com.example.demo'], 'kept' => true, 'retired_builds' => 1])
        ->and($build->refresh()->status)->toBe(SignedBuildStatus::Expired)
        ->and($build->status_reason)->toBe('KEEP_BUNDLE_ID_CHANGED')
        ->and(app(KeptBundleIds::class)->filed())->toBe(['com.example.demo']);

    expect(deviceTest('keep', ['target' => $this->catalogApp->id, '--off' => true])['kept'])->toBeFalse()
        ->and(app(KeptBundleIds::class)->filed())->toBe([]);
});

it('keeps the operator list from .env and the file list together', function () {
    config(['storefront.signing.compat_shim.keep_bundle_ids' => ['ru.yandex.*']]);
    $list = app(KeptBundleIds::class);
    $list->add('com.example.game');
    $list->add('com.example.game');

    expect($list->patterns())->toBe(['ru.yandex.*', 'com.example.game'])
        ->and($list->matches('ru.yandex.disk'))->toBeTrue()
        ->and($list->matches('com.example.game'))->toBeTrue()
        ->and($list->matches('com.example.other'))->toBeFalse();

    $list->remove('com.example.game');
    expect($list->filed())->toBe([]);
});
