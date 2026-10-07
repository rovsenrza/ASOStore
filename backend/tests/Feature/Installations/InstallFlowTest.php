<?php

use App\Enums\ArtifactStatus;
use App\Enums\DeviceFamily;
use App\Enums\InstallationStatus;
use App\Enums\PipelineJobStatus;
use App\Enums\RoleSlug;
use App\Enums\SignedBuildStatus;
use App\Http\Middleware\VerifyWorkerSignature;
use App\Jobs\InspectArtifactJob;
use App\Jobs\PrepareSigningJob;
use App\Jobs\VerifySignatureJob;
use App\Jobs\WarmBuildJob;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\Certificate;
use App\Models\Device;
use App\Models\Installation;
use App\Models\PipelineJob;
use App\Models\RefreshToken;
use App\Models\Runner;
use App\Models\SignedBuild;
use App\Models\SigningProfile;
use App\Models\StorefrontClaim;
use App\Services\Apple\AppGroupProvisioner;
use App\Services\Apple\AppGroupUnavailable;
use App\Services\Artifacts\ArtifactFileCache;
use App\Services\Audit\Actor;
use App\Services\Devices\DeviceRegistrationService;
use App\Services\Imports\LinkFetcher;
use App\Services\Installations\InstallationService;
use App\Services\Pipeline\PipelineJobService;
use App\Services\Signing\BuildWarmup;
use App\Services\Signing\SigningService;
use App\StateMachines\StateMachine;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakeLinkFetcher;
use Tests\Support\IpaBuilder;
use Tests\Support\OpenApiContract;
use Tests\Support\RecordingAppGroups;
use Tests\Support\ScriptedApple;

const RUNNER_SECRET = 'test-runner-secret-0123456789abcdef';
const CERT_SHA1 = 'A1B2C3D4E5F60718293A4B5C6D7E8F9012345678';

beforeEach(function () {
    Storage::fake('artifacts');
    $this->team = connectFakeAppleTeam();
    approveTeamFor('com.example.demo', $this->team);

    // A published app with an inspected, approved and published artifact.
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

    // A customer whose iPhone is registered with the (fake) Apple team.
    $this->customer = subscribedCustomer();
    postEnrollment(enrollmentChallenge($this->customer), ['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '18.6']);
    forgetGuards();
    $this->device = Device::sole();

    $this->runner = Runner::create(['key_id' => 'rk_test', 'name' => 'mac-1', 'secret_encrypted' => RUNNER_SECRET]);
});

/**
 * A request from the runner, signed as runner/Sources/Runner/WorkerClient.swift signs it.
 */
function worker(string $method, string $path, string $body = '', string $secret = RUNNER_SECRET, ?string $nonce = null): TestResponse
{
    $timestamp = (string) time();
    $nonce ??= Str::random(24);
    $hash = hash('sha256', $body);

    return test()->call($method, $path, [], [], [], [
        'HTTP_X_RUNNER_KEY' => 'rk_test',
        'HTTP_X_TIMESTAMP' => $timestamp,
        'HTTP_X_NONCE' => $nonce,
        'HTTP_X_CONTENT_SHA256' => $hash,
        'HTTP_X_SIGNATURE' => VerifyWorkerSignature::sign($secret, $method, $path, $timestamp, $nonce, $hash),
        'CONTENT_TYPE' => $method === 'PUT' ? 'application/octet-stream' : 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body);
}

function runnerHeartbeat(): TestResponse
{
    return worker('POST', '/api/worker/v1/heartbeat', json_encode([
        'version' => '1.0.0',
        'identities' => [[
            'sha1' => CERT_SHA1,
            'serial_number' => '7A1B2C3D',
            'team_identifier' => AppleTeamIdFixture::id(),
            'common_name' => 'Apple Distribution: Example (TEAMID)',
            'expires_at' => now()->addMonths(6)->toIso8601ZuluString(),
        ]],
    ]));
}

/**
 * What a well-behaved runner produces: the same app, with the device profile embedded.
 */
function signedIpa(SigningProfile $profile, ?string $bundle = null): string
{
    return IpaBuilder::app($bundle ?? 'com.example.demo')
        ->withAppFile('embedded.mobileprovision', base64_decode($profile->content_encrypted))
        ->executable(IpaBuilder::machO(entitlements: ['application-identifier' => $profile->team->apple_team_id.'.com.example.demo', 'get-task-allow' => false]))
        ->build();
}

final class AppleTeamIdFixture
{
    public static function id(): string
    {
        return AppleTeam::primary()->apple_team_id;
    }
}

function runnerSigns(object $test): array
{
    $lease = worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->json('data');
    expect($lease)->not->toBeNull();

    $source = worker('GET', $lease['source']['path'])->assertOk();
    expect(hash('sha256', $source->streamedContent()))->toBe($lease['source']['sha256']);

    $profile = SigningProfile::sole();
    $signed = signedIpa($profile);
    worker('PUT', $lease['upload_path'], $signed)->assertCreated()->assertJsonPath('data.sha256', hash('sha256', $signed));
    worker('POST', $lease['result_path'], json_encode(['status' => 'succeeded', 'sha256' => hash('sha256', $signed), 'report' => ['codesign' => 'valid']]))
        ->assertOk();

    return $lease;
}

it('installs a published app on a registered iPhone through the whole flow', function () {
    runnerHeartbeat()->assertOk();
    expect(Certificate::sole()->runner_id)->toBe($this->runner->id);

    // The app asks to prepare; with no deliverable build yet it starts signing.
    $token = $this->customer->createToken('ios')->plainTextToken;
    RefreshToken::create([
        'user_id' => $this->customer->id, 'device_id' => $this->device->id, 'family_id' => 'f1',
        'token_hash' => hash('sha256', 'r'), 'access_token_id' => $this->customer->tokens()->latest('id')->value('id'),
        'expires_at' => now()->addDay(),
    ]);
    $prepared = $this->withToken($token)->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'PREPARING');
    expect(OpenApiContract::errors($prepared->getContent(), 'InstallationResponse'))->toBe([]);
    $installation = $prepared->json('data');

    $this->getJson("/api/v1/apps/{$this->catalogApp->public_id}")->assertJsonPath('data.install_state.status', 'preparing');

    // PrepareSigningJob provisioned an ad hoc profile for (team, bundle) listing the team's devices
    // and queued the runner job; the build is the team's, not this device's alone.
    $profile = SigningProfile::sole();
    expect($profile->bundle_identifier)->toBe('com.example.demo')
        ->and($profile->isShared())->toBeTrue()
        ->and($profile->covers($this->device))->toBeTrue()
        ->and(SignedBuild::sole()->isShared())->toBeTrue()
        // Unique per creation: Apple refuses a duplicate name, even one this database never saw.
        ->and($profile->name)->toMatch('/ \d{12} com\.example\.demo$/')
        ->and(PipelineJob::where('type', SigningService::RUNNER_JOB_TYPE)->sole()->status)->toBe(PipelineJobStatus::Queued);

    runnerSigns($this);

    $build = SignedBuild::sole();
    expect($build->status)->toBe(SignedBuildStatus::Deliverable)
        ->and($build->sha256)->not->toBe($this->artifact->sha256);
    $this->getJson("/api/v1/installations/{$installation['id']}")->assertJsonPath('data.status', 'READY_TO_INSTALL');

    // Authorize → itms-services link → manifest → IPA.
    $authorized = $this->postJson("/api/v1/installations/{$installation['id']}/authorize")->assertOk();
    expect(OpenApiContract::errors($authorized->getContent(), 'InstallLinkResponse'))->toBe([]);
    $link = $authorized->json('data');
    expect($link['install_url'])->toStartWith('itms-services://?action=download-manifest&url=');

    forgetGuards();
    $manifest = $this->get($link['manifest_url'])->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    preg_match('#<string>(http[^<]+/downloads/installations/[^<]+)</string>#', $manifest->getContent(), $match);
    $downloadUrl = html_entity_decode($match[1]);
    expect($manifest->getContent())->toContain('<string>com.example.demo</string>');

    // The manifest token is single-use.
    $this->get($link['manifest_url'])->assertStatus(410)->assertJsonPath('error.code', 'INSTALL_TOKEN_EXPIRED');

    $download = $this->get($downloadUrl)->assertOk();
    expect(file_get_contents($download->baseResponse->getFile()->getPathname()))->toBe(Storage::disk('artifacts')->get($build->storage_path));
    app()->terminate();

    $model = Installation::sole();
    expect($model->status)->toBe(InstallationStatus::Delivered)
        ->and($model->events()->pluck('type')->all())->toBe(['PREPARE_REQUESTED', 'READY', 'AUTHORIZED', 'MANIFEST_FETCHED', 'DOWNLOAD_STARTED', 'DOWNLOAD_COMPLETED']);

    $library = $this->withToken($token)->getJson('/api/v1/library')->assertOk()->assertJsonPath('data.0.status', 'DELIVERED');
    expect(OpenApiContract::errors($library->getContent(), 'LibraryResponse'))->toBe([]);

    // The customer may have deleted the app since: it can be installed again, from the same
    // signed build, so it is ready at once with no second signing.
    $this->withToken($token)->getJson("/api/v1/apps/{$this->catalogApp->public_id}")->assertJsonPath('data.install_state.status', 'get');
    $this->withToken($token)->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")
        ->assertOk()
        ->assertJsonPath('data.status', 'READY_TO_INSTALL');
    expect(SignedBuild::count())->toBe(1)
        ->and(Installation::count())->toBe(2);
});

it('signs for a team other than the primary under that team\'s own bundle ID', function () {
    // Apple keeps one App ID per identifier across all teams; the primary team holds the base ones.
    runnerHeartbeat()->assertOk();
    $this->team->forceFill(['is_primary' => false])->save();
    $bundle = 'com.example.demo.'.strtolower($this->team->apple_team_id);

    $installation = app(InstallationService::class)->prepare($this->customer, $this->device, $this->catalogApp);
    $profile = SigningProfile::sole();
    expect($profile->bundle_identifier)->toBe($bundle);

    $lease = worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->json('data');
    expect($lease['bundle_identifier'])->toBe($bundle);
    $signed = IpaBuilder::app($bundle)
        ->withAppFile('embedded.mobileprovision', base64_decode($profile->content_encrypted))
        ->executable(IpaBuilder::machO(entitlements: ['application-identifier' => $this->team->apple_team_id.'.'.$bundle, 'get-task-allow' => false]))
        ->build();
    worker('PUT', $lease['upload_path'], $signed)->assertCreated();
    worker('POST', $lease['result_path'], json_encode(['status' => 'succeeded', 'sha256' => hash('sha256', $signed), 'report' => ['codesign' => 'valid']]))->assertOk();
    expect(SignedBuild::sole()->status)->toBe(SignedBuildStatus::Deliverable);

    Sanctum::actingAs($this->customer);
    $link = $this->postJson("/api/v1/installations/{$installation->public_id}/authorize")->assertOk()->json('data');
    forgetGuards();
    expect($this->get($link['manifest_url'])->assertOk()->getContent())->toContain("<string>{$bundle}</string>");
});

it('delivers object storage IPAs with range support, with and without a cached upload', function (bool $cached) {
    runnerHeartbeat();
    Sanctum::actingAs($this->customer);
    $installation = $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202)->json('data');
    runnerSigns($this);
    $link = $this->postJson("/api/v1/installations/{$installation['id']}/authorize")->assertOk()->json('data');
    forgetGuards();
    preg_match('#<string>(http[^<]+/downloads/installations/[^<]+)</string>#', $this->get($link['manifest_url'])->getContent(), $match);
    $downloadUrl = html_entity_decode($match[1]);
    $path = SignedBuild::sole()->storage_path;
    $bytes = Storage::disk('artifacts')->get($path);

    // The same file, now served by an S3 bucket (the AWS SDK's mock handler plays the bucket).
    $requests = [];
    $bucket = function (CommandInterface $command) use ($bytes, $path, &$requests) {
        $requests[] = [$command->getName(), $command['Key'] ?? null, $command['Range'] ?? null];
        if ($command['Key'] !== $path) {
            throw new RuntimeException('Unexpected key '.$command['Key']);
        }
        if ($command->getName() === 'HeadObject') {
            return new Result(['ContentLength' => strlen($bytes), 'ETag' => '"object-version-1"']);
        }
        preg_match('/^bytes=(\d+)-(\d+)$/', (string) $command['Range'], $range);

        return new Result(['Body' => Utils::streamFor(substr($bytes, (int) $range[1], (int) $range[2] - (int) $range[1] + 1))]);
    };
    $mock = new MockHandler;
    foreach (range(1, 12) as $ignored) {
        $mock->append($bucket);
    }
    Storage::set('artifacts', Storage::build([
        'driver' => 's3', 'key' => 'key', 'secret' => 'secret', 'region' => 'default', 'bucket' => 'ruappstore-artifacts',
        'endpoint' => 'https://storage.test', 'use_path_style_endpoint' => true, 'throw' => true, 'handler' => $mock,
    ]));

    if ($cached) {
        $this->artifactCacheRoot = sys_get_temp_dir().'/delivery-cache-'.bin2hex(random_bytes(8));
        config(['storefront.artifacts.file_cache_path' => $this->artifactCacheRoot]);
        $source = fopen('php://temp', 'w+b');
        fwrite($source, $bytes);
        app(ArtifactFileCache::class)->store(Storage::disk('artifacts'), $path, hash('sha256', $bytes), strlen($bytes), $source);
        fclose($source);
    }
    $content = function ($response) use ($cached) {
        if (! $cached) {
            return $response->streamedContent();
        }
        ob_start();
        $response->baseResponse->sendContent();

        return ob_get_clean();
    };
    $full = $this->get($downloadUrl)->assertOk()->assertHeader('Accept-Ranges', 'bytes')->assertHeader('Content-Length', (string) strlen($bytes));
    expect($content($full))->toBe($bytes);
    app()->terminate();
    expect(Installation::sole()->status)->toBe(InstallationStatus::Delivered);

    $part = $this->withHeader('Range', 'bytes=10-19')->get($downloadUrl)
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 10-19/'.strlen($bytes));
    expect($content($part))->toBe(substr($bytes, 10, 10));
    if ($cached) {
        expect(array_column($requests, 0))->not->toContain('GetObject');
    } else {
        expect($requests)->toContain(['GetObject', $path, 'bytes=10-19']);
    }

    $this->withHeader('Range', 'bytes='.(strlen($bytes) + 5).'-')->get($downloadUrl)
        ->assertStatus(416)
        ->assertHeader('Content-Range', 'bytes */'.strlen($bytes));
})->with([false, true]);

afterEach(function () {
    if (isset($this->artifactCacheRoot)) {
        app('files')->deleteDirectory($this->artifactCacheRoot);
    }
});

it('leases a ten minute source URL for object storage without exposing runner credentials', function () {
    runnerHeartbeat();
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202);
    Storage::set('artifacts', Storage::build([
        'driver' => 's3', 'key' => 'object-key', 'secret' => 'object-secret', 'region' => 'default',
        'bucket' => 'artifacts', 'endpoint' => 'https://storage.test', 'use_path_style_endpoint' => true,
    ]));
    $source = worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->json('data.source');
    parse_str(parse_url($source['download_url'], PHP_URL_QUERY), $query);
    expect(parse_url($source['download_url'], PHP_URL_SCHEME))->toBe('https')
        ->and($query['X-Amz-Expires'])->toBe('600')
        ->and($source['download_url'])->not->toContain(RUNNER_SECRET)
        ->and($source['sha256'])->toBe($this->artifact->sha256);
});

it('adds the compatibility shim to the lease only for apps that share through groups', function () {
    runnerHeartbeat();
    $shim = tempnam(sys_get_temp_dir(), 'shim').'.dylib';
    file_put_contents($shim, "\xCA\xFE\xBA\xBE fake");
    config(['storefront.signing.compat_shim.enabled' => true, 'storefront.signing.compat_shim.path' => $shim]);

    // The fixture app declares no App Group: no shim.
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202);
    expect(worker('POST', '/api/worker/v1/leases', '{}')->json('data.inject_dylibs'))->toBe([]);
    forgetGuards();

    // An app that shares through an App Group gets the shim injected.
    $manager = userWithRoles(RoleSlug::CatalogManager);
    $grouped = CatalogApp::factory()->create(['visibility' => 'PUBLISHED']);
    approveTeamFor('com.example.grouped');
    $ipa = IpaBuilder::app('com.example.grouped')
        ->executable(IpaBuilder::machO(entitlements: [
            'application-identifier' => 'ABCDE12345.com.example.grouped',
            'com.apple.security.application-groups' => ['group.com.example.grouped'],
        ]))->build();
    $artifact = inspected(uploadIpa($manager, $grouped, $ipa));
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/review", [
        'decision' => 'approve',
        'checklist' => ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true],
        'acknowledge_scan_result' => true,
    ])->assertOk();
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/publish")->assertOk();
    forgetGuards();
    approveTeamFor('com.example.grouped');

    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/apps/{$grouped->public_id}/prepare")->assertStatus(202);
    $lease = worker('POST', '/api/worker/v1/leases', '{}')->json('data');
    expect($lease['inject_dylibs'])->toHaveCount(1)
        ->and($lease['inject_dylibs'][0]['name'])->toBe('RuStoreCompat.dylib')
        ->and(base64_decode($lease['inject_dylibs'][0]['content']))->toBe(file_get_contents($shim))
        // The vendor's team ID rides along, so the runner can re-prefix Info.plist values built from it.
        ->and($lease['original_team_identifier'])->toBe('ABCDE12345');
    @unlink($shim);
});

it('leases separate jobs to concurrent workers sharing one runner identity', function () {
    runnerHeartbeat();
    foreach (range(1, 2) as $ignored) {
        $build = SignedBuild::create(['artifact_id' => $this->artifact->id, 'device_id' => $this->device->id]);
        app(SigningService::class)->prepare($build);
    }
    $first = worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->json('data');
    $second = worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->json('data');
    expect($first['job_id'])->not->toBe($second['job_id'])
        ->and($first['signed_build_id'])->not->toBe($second['signed_build_id']);
    worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->assertJsonPath('data', null);
});

it('removes a deleted website listing from the customer catalog and library', function () {
    runnerHeartbeat();
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202);
    runnerSigns($this);
    $this->getJson('/api/v1/library')->assertOk()->assertJsonCount(1, 'data');

    forgetGuards();
    asStaff(userWithRoles(RoleSlug::CatalogManager))
        ->deleteJson("/api/v1/admin/apps/{$this->catalogApp->public_id}", ['reason' => 'Removed from website'])
        ->assertOk();

    forgetGuards();
    Sanctum::actingAs($this->customer);
    $this->getJson('/api/v1/library')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/apps')->assertOk()->assertJsonCount(0, 'data');
});

it('never hands out a manifest or IPA for tampered links, other devices or withdrawn artifacts', function () {
    runnerHeartbeat();
    Sanctum::actingAs($this->customer);
    $installation = $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->json('data');
    runnerSigns($this);
    $link = $this->postJson("/api/v1/installations/{$installation['id']}/authorize")->assertOk()->json('data');

    // Another customer cannot see or authorize this installation.
    Sanctum::actingAs(subscribedCustomer());
    $this->postJson("/api/v1/installations/{$installation['id']}/authorize")->assertStatus(403);

    forgetGuards();
    $manifest = $this->get($link['manifest_url'])->assertOk()->getContent();
    preg_match('#<string>(http[^<]+/downloads/installations/[^<]+)</string>#', $manifest, $match);
    $url = html_entity_decode($match[1]);

    $this->get($url.'x')->assertForbidden();
    $this->travel(11)->minutes();
    $this->get($url)->assertForbidden();
    $this->travelBack();
    expect(AuditLog::where('action', 'installation.download_rejected')->count())->toBe(2);

    // Revoking the artifact stops delivery at once.
    $admin = userWithRoles(RoleSlug::Admin);
    forgetGuards();
    asStaff($admin)->postJson("/api/v1/admin/artifacts/{$this->artifact->public_id}/revoke", ['reason' => 'Withdrawn'])->assertOk();
    forgetGuards();
    $this->get($url)->assertStatus(409)->assertJsonPath('error.code', 'ARTIFACT_NOT_INSTALLABLE');
    expect(SignedBuild::sole()->status)->toBe(SignedBuildStatus::Revoked)
        ->and(Installation::sole()->status)->toBe(InstallationStatus::Failed);
});

it('refuses preparation for devices that are not eligible or not compatible', function () {
    $other = subscribedCustomer();
    Sanctum::actingAs($other);
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertJsonPath('error.code', 'DEVICE_NOT_ELIGIBLE');

    $this->device->forceFill(['os_version' => '17.4'])->save();
    forgetGuards();
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'INCOMPATIBLE_DEVICE');

    // The same iOS 17.4 as an older enrollment stored it: the build number.
    $this->device->forceFill(['os_version' => '21E219'])->save();
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")
        ->assertForbidden()
        ->assertJsonPath('error.code', 'INCOMPATIBLE_DEVICE')
        ->assertJsonPath('error.details.device_os_version', '17.4');
    expect(Installation::count())->toBe(0);
});

it('lets the customer start over when the signed build of a ready installation is gone', function () {
    runnerHeartbeat();
    $token = $this->customer->createToken('ios')->plainTextToken;
    RefreshToken::create([
        'user_id' => $this->customer->id, 'device_id' => $this->device->id, 'family_id' => 'f1',
        'token_hash' => hash('sha256', 'r'), 'access_token_id' => $this->customer->tokens()->latest('id')->value('id'),
        'expires_at' => now()->addDay(),
    ]);
    $installation = $this->withToken($token)->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202)->json('data');
    runnerSigns($this);
    // Expired on its own, as the launch-shim re-sign did, while the installation still says ready.
    app(StateMachine::class)->transition(SignedBuild::sole(), SignedBuildStatus::Expired, 'Re-signed', Actor::system('test'), extra: ['status_reason' => 'COMPAT_RESIGN']);

    $this->withToken($token)->getJson("/api/v1/apps/{$this->catalogApp->public_id}")->assertJsonPath('data.install_state.status', 'get');
    $this->withToken($token)->postJson("/api/v1/installations/{$installation['id']}/authorize")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ARTIFACT_NOT_INSTALLABLE');
    expect(Installation::sole()->status)->toBe(InstallationStatus::Expired)
        ->and(Installation::sole()->status_reason)->toBe('BUILD_EXPIRED');

    // The next tap signs a new build.
    $this->withToken($token)->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'PREPARING');
    expect(SignedBuild::count())->toBe(2)
        ->and(Installation::count())->toBe(2);
});

it('keeps jobs queued while no runner is online and finishes after restart without signing twice', function () {
    runnerHeartbeat();
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202);
    $job = PipelineJob::where('type', SigningService::RUNNER_JOB_TYPE)->sole();

    // The runner leases the job, then dies.
    worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->assertJsonPath('data.job_id', $job->public_id);
    expect(SignedBuild::sole()->status)->toBe(SignedBuildStatus::Signing);

    $this->travel(11)->minutes();
    expect(app(SigningService::class)->recoverExpiredLeases())->toBe(1)
        ->and($job->refresh()->status)->toBe(PipelineJobStatus::Queued)
        ->and(SignedBuild::sole()->status)->toBe(SignedBuildStatus::SigningPending);

    // A second prepare does not create a second build or job.
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202);
    expect(SignedBuild::count())->toBe(1)->and(PipelineJob::where('type', SigningService::RUNNER_JOB_TYPE)->count())->toBe(1);

    runnerHeartbeat();
    runnerSigns($this);
    expect(SignedBuild::sole()->status)->toBe(SignedBuildStatus::Deliverable)
        ->and($job->refresh()->attempt)->toBe(2);
});

it('queues a fresh runner job when an installation is retried after signing failed', function () {
    runnerHeartbeat();
    Sanctum::actingAs($this->customer);

    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202);
    $failedBuild = SignedBuild::sole();
    app(SigningService::class)->failBuild($failedBuild, 'SIGNING_FAILED', 'First signing attempt failed.');

    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertStatus(202);
    $jobs = PipelineJob::where('type', SigningService::RUNNER_JOB_TYPE)->orderBy('id')->get();
    $newBuild = SignedBuild::latest('id')->firstOrFail();

    expect($jobs)->toHaveCount(2)
        ->and($jobs[0]->subject_id)->toBe($failedBuild->id)
        ->and($jobs[1]->subject_id)->toBe($newBuild->id)
        ->and($jobs[1]->status)->toBe(PipelineJobStatus::Queued);
});

it('rejects a signed build whose profile does not cover the device', function () {
    runnerHeartbeat();
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare");
    $lease = worker('POST', '/api/worker/v1/leases', '{}')->json('data');

    $wrong = IpaBuilder::app()->withAppFile('embedded.mobileprovision', ScriptedApple::mobileprovision(
        SigningProfile::sole()->uuid, AppleTeamIdFixture::id(), 'com.example.demo', ['00000000-0000000000000000'],
    ))->build();
    worker('PUT', $lease['upload_path'], $wrong)->assertCreated();
    worker('POST', $lease['result_path'], json_encode(['status' => 'succeeded', 'sha256' => hash('sha256', $wrong)]))->assertOk();

    expect(SignedBuild::sole()->status)->toBe(SignedBuildStatus::ValidationFailed)
        ->and(SignedBuild::sole()->status_reason)->toBe('DEVICE_NOT_IN_PROFILE')
        ->and(Installation::sole()->status)->toBe(InstallationStatus::Failed);
});

it('authenticates the runner with a fresh HMAC signature and a single-use nonce', function () {
    worker('POST', '/api/worker/v1/heartbeat', '{}', secret: 'wrong')->assertUnauthorized();
    worker('POST', '/api/worker/v1/heartbeat', '{}', nonce: 'nonce-used-once-0001')->assertOk();
    worker('POST', '/api/worker/v1/heartbeat', '{}', nonce: 'nonce-used-once-0001')->assertUnauthorized();

    $this->runner->forceFill(['status' => 'DISABLED'])->save();
    worker('POST', '/api/worker/v1/heartbeat', '{}')->assertUnauthorized();
});

it('fails preparation clearly when no runner holds a signing certificate', function () {
    Sanctum::actingAs($this->customer);
    // Provisioning runs at once in the sync test queue, so the answer already shows the failure.
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")->assertOk()->assertJsonPath('data.status', 'FAILED');

    expect(SignedBuild::sole()->status)->toBe(SignedBuildStatus::SigningFailed)
        ->and(SignedBuild::sole()->status_reason)->toBe('NO_SIGNING_CERTIFICATE')
        ->and(Installation::sole()->status)->toBe(InstallationStatus::Failed);
    $this->getJson("/api/v1/apps/{$this->catalogApp->public_id}")->assertJsonPath('data.install_state.status', 'failed');
    expect(AppArtifact::sole()->status)->toBe(ArtifactStatus::Published);
});

it('signs a fresh build after the certificate of the old one is revoked', function () {
    runnerHeartbeat();
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare");
    runnerSigns($this);
    $old = SignedBuild::sole();
    expect($old->isDeliverable())->toBeTrue();

    $this->artisan('certificate:revoke', ['sha1' => CERT_SHA1])->assertFailed();
    $this->artisan('certificate:revoke', ['sha1' => CERT_SHA1, '--reason' => 'Key leaked'])->assertSuccessful();

    expect($old->refresh()->status)->toBe(SignedBuildStatus::Revoked)
        ->and($old->status_reason)->toBe('CERTIFICATE_REVOKED');
});

/**
 * A published VPN-style app whose own bundle ID belongs to another team: the listing
 * signs it as com.ruappstore.vpn, and its packet-tunnel extension goes along.
 */
function preparedVpnApp(object $test, bool $widget = false): CatalogApp
{
    runnerHeartbeat()->assertOk();
    approveTeamFor('com.ruappstore.vpn', $test->team);

    $manager = userWithRoles(RoleSlug::CatalogManager);
    $listing = CatalogApp::factory()->create(['visibility' => 'PUBLISHED', 'bundle_identifier' => 'com.ruappstore.vpn']);
    $tunnel = ['application-identifier' => 'OTHERTEAM1.org.example.vpn.tunnel', 'com.apple.developer.networking.networkextension' => ['packet-tunnel-provider']];
    $ipa = IpaBuilder::app('org.example.vpn')
        ->executable(IpaBuilder::machO(entitlements: ['application-identifier' => 'OTHERTEAM1.org.example.vpn', 'com.apple.security.application-groups' => ['group.org.example.vpn']]))
        ->withExtension('Tunnel', 'org.example.vpn.tunnel', entitlements: $tunnel);
    if ($widget) {
        $ipa->withExtension('Widget', 'org.example.vpn.widget', entitlements: ['application-identifier' => 'OTHERTEAM1.org.example.vpn.widget']);
    }
    $artifact = inspected(uploadIpa($manager, $listing, $ipa->build()));
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/review", [
        'decision' => 'approve',
        'checklist' => ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true],
        'acknowledge_scan_result' => true,
    ])->assertOk();
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/publish")->assertOk();
    forgetGuards();

    expect($artifact->refresh()->signingExtensions()[0])->toBe([
        'path' => 'PlugIns/Tunnel.appex',
        'source_bundle_identifier' => 'org.example.vpn.tunnel',
        'bundle_identifier' => 'com.ruappstore.vpn.tunnel',
        'entitlements' => $tunnel,
    ]);

    $token = $test->customer->createToken('ios')->plainTextToken;
    RefreshToken::create([
        'user_id' => $test->customer->id, 'device_id' => $test->device->id, 'family_id' => 'f-vpn',
        'token_hash' => hash('sha256', 'r-vpn'), 'access_token_id' => $test->customer->tokens()->latest('id')->value('id'),
        'expires_at' => now()->addDay(),
    ]);
    $test->withToken($token)->postJson("/api/v1/apps/{$listing->public_id}/prepare")->assertStatus(202);
    $test->vpnToken = $token;

    return $listing;
}

/**
 * Uploads what the runner produced for the leased VPN build and reports success.
 *
 * @param  array<string, mixed>  $lease
 */
function runnerReturns(array $lease, string $signed): void
{
    worker('PUT', $lease['upload_path'], $signed)->assertCreated();
    worker('POST', $lease['result_path'], json_encode(['status' => 'succeeded', 'sha256' => hash('sha256', $signed), 'report' => ['codesign' => 'valid']]))->assertOk();
}

it('signs an app extension with its own profile under the listing bundle ID', function () {
    $listing = preparedVpnApp($this);

    // One profile per bundle ID, both under the listing's ID.
    expect(SigningProfile::orderBy('id')->pluck('bundle_identifier')->all())->toBe(['com.ruappstore.vpn', 'com.ruappstore.vpn.tunnel']);
    $main = SigningProfile::where('bundle_identifier', 'com.ruappstore.vpn')->sole();
    $extension = SigningProfile::where('bundle_identifier', 'com.ruappstore.vpn.tunnel')->sole();

    $lease = worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->json('data');
    expect($lease['bundle_identifier'])->toBe('com.ruappstore.vpn')
        ->and($lease['profile']['uuid'])->toBe($main->uuid)
        ->and($lease['nested'])->toBe([[
            'path' => 'PlugIns/Tunnel.appex',
            'bundle_identifier' => 'com.ruappstore.vpn.tunnel',
            'profile' => ['uuid' => $extension->uuid, 'content' => $extension->content_encrypted],
        ]]);

    // A well-behaved runner: the app and its extension re-identified, each with its own profile.
    $team = $main->team->apple_team_id;
    runnerReturns($lease, IpaBuilder::app('com.ruappstore.vpn')
        ->withAppFile('embedded.mobileprovision', base64_decode($main->content_encrypted))
        ->executable(IpaBuilder::machO(entitlements: ['application-identifier' => "{$team}.com.ruappstore.vpn"]))
        ->withExtension('Tunnel', 'com.ruappstore.vpn.tunnel', entitlements: ['application-identifier' => "{$team}.com.ruappstore.vpn.tunnel"],
            profile: base64_decode($extension->content_encrypted))
        ->build());

    $build = SignedBuild::sole();
    expect($build->status)->toBe(SignedBuildStatus::Deliverable);

    // The install manifest names the ID the signed app carries.
    $installation = Installation::where('app_id', $listing->id)->sole();
    $link = $this->withToken($this->vpnToken)->postJson("/api/v1/installations/{$installation->public_id}/authorize")->assertOk()->json('data');
    forgetGuards();
    expect($this->get($link['manifest_url'])->assertOk()->getContent())->toContain('<string>com.ruappstore.vpn</string>');
});

it('gives extensions another team\'s ID under the app\'s', function () {
    $artifact = preparedVpnApp($this)->publishedArtifact;
    $other = new AppleTeam(['apple_team_id' => 'WU5Y6G68J9']);

    expect($artifact->signingBundleIdentifier($other))->toBe('com.ruappstore.vpn.wu5y6g68j9')
        ->and(array_column($artifact->signingExtensions($other), 'bundle_identifier'))->toBe(['com.ruappstore.vpn.wu5y6g68j9.tunnel'])
        // The primary team keeps the base IDs it already holds at Apple.
        ->and($artifact->signingBundleIdentifier($this->team))->toBe('com.ruappstore.vpn');
});

it('makes the profiles of several extensions side by side, and reuses them next time', function () {
    preparedVpnApp($this, widget: true);

    expect(SigningProfile::orderBy('bundle_identifier')->pluck('bundle_identifier')->all())
        ->toBe(['com.ruappstore.vpn', 'com.ruappstore.vpn.tunnel', 'com.ruappstore.vpn.widget']);
    $uuids = SigningProfile::orderBy('id')->pluck('uuid')->all();

    $build = SignedBuild::sole();
    expect(app(SigningService::class)->prepare($build->fresh()))->toBe('QUEUED_FOR_RUNNER')
        ->and(SigningProfile::orderBy('id')->pluck('uuid')->all())->toBe($uuids);
});

it('routes Apple work and file work to separate queues', function () {
    expect((new PrepareSigningJob(1))->queue)->toBe('apple')
        ->and((new VerifySignatureJob(1))->queue)->toBe('files')
        ->and((new InspectArtifactJob(1))->queue)->toBe('files');
});

it('rejects a signed build whose extension kept its original bundle ID', function () {
    preparedVpnApp($this);
    $lease = worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->json('data');
    $main = SigningProfile::where('bundle_identifier', 'com.ruappstore.vpn')->sole();
    $team = $main->team->apple_team_id;

    runnerReturns($lease, IpaBuilder::app('com.ruappstore.vpn')
        ->withAppFile('embedded.mobileprovision', base64_decode($main->content_encrypted))
        ->executable(IpaBuilder::machO(entitlements: ['application-identifier' => "{$team}.com.ruappstore.vpn"]))
        ->withExtension('Tunnel', 'org.example.vpn.tunnel')
        ->build());

    expect(SignedBuild::sole()->status)->toBe(SignedBuildStatus::ValidationFailed)
        ->and(SignedBuild::sole()->status_reason)->toBe('EXTENSION_ID_CHANGED');
});

it('assigns the app its own App Group before its profiles are made', function () {
    $portal = new RecordingAppGroups;
    app()->instance(AppGroupProvisioner::class, $portal);

    preparedVpnApp($this);

    // The app asks for an App Group (its tunnel extension does not): one group, named after the signing ID.
    expect($portal->calls)->toBe([['group.com.ruappstore.vpn', 'com.ruappstore.vpn']])
        ->and(SigningProfile::count())->toBe(2);

    // The group belongs to the App ID: a new profile for it (another device, a renewal) skips the portal.
    SigningProfile::query()->delete();
    app(SigningService::class)->prepare(SignedBuild::sole());
    expect($portal->calls)->toHaveCount(1)
        ->and(SigningProfile::count())->toBe(2);
});

it('still signs when the Apple ID session for App Groups has expired, and tells the operator', function () {
    $portal = new RecordingAppGroups;
    $portal->failure = new AppGroupUnavailable('SESSION_EXPIRED', 'Two-factor code required');
    app()->instance(AppGroupProvisioner::class, $portal);

    preparedVpnApp($this);

    expect(SigningProfile::count())->toBe(2)
        ->and(AuditLog::where('action', 'signing.app_group_unavailable')->sole()->reason)->toContain('apple:portal-login');
});

it('runs a preparation again after its queue worker was killed mid-attempt', function () {
    $jobs = app(PipelineJobService::class);
    $job = $jobs->create(PrepareSigningJob::TYPE, 'stale-prepare', $this->customer);
    $jobs->start($job, 'queue:old');

    // Still running within the job timeout: another worker has it.
    (new PrepareSigningJob($job->id))->handle($jobs);
    expect($job->fresh()->status)->toBe(PipelineJobStatus::Running);

    // The queue redelivers it after the worker died; the dead attempt is closed and the job runs.
    $this->travel(6)->minutes();
    (new PrepareSigningJob($job->id))->handle($jobs);

    $job->refresh();
    expect($job->status)->toBe(PipelineJobStatus::Succeeded)
        ->and($job->attempt)->toBe(2)
        ->and($job->attempts()->orderBy('id')->pluck('result_code')->all())->toBe(['ERROR', 'SUBJECT_MISSING']);
});

it('prepares a complete build from the app page and reuses it when the customer installs', function () {
    runnerHeartbeat()->assertOk();
    $token = $this->customer->createToken('ios')->plainTextToken;
    RefreshToken::create([
        'user_id' => $this->customer->id, 'device_id' => $this->device->id, 'family_id' => 'f-warm',
        'token_hash' => hash('sha256', 'r-warm'), 'access_token_id' => $this->customer->tokens()->latest('id')->value('id'),
        'expires_at' => now()->addDay(),
    ]);

    $this->withToken($token)->getJson("/api/v1/apps/{$this->catalogApp->public_id}")->assertOk();
    // A complete background build, without a customer installation or authorization.
    expect(SigningProfile::sole()->bundle_identifier)->toBe('com.example.demo')
        ->and(SignedBuild::count())->toBe(1)
        ->and(Installation::count())->toBe(0)
        ->and(PipelineJob::where('type', SigningService::RUNNER_JOB_TYPE)->sole()->payload['priority'])->toBe(10);

    runnerSigns($this);
    $build = SignedBuild::sole();
    expect($build->status)->toBe(SignedBuildStatus::Deliverable);

    Queue::fake();
    $this->withToken($token)->getJson("/api/v1/apps/{$this->catalogApp->public_id}")->assertOk();
    Queue::assertNotPushed(WarmBuildJob::class);

    // The install finds the profile made: no second one.
    Queue::fake([]);
    $uuid = SigningProfile::sole()->uuid;
    $this->withToken($token)->postJson("/api/v1/apps/{$this->catalogApp->public_id}/prepare")
        ->assertOk()->assertJsonPath('data.status', 'READY_TO_INSTALL');
    expect(SigningProfile::sole()->uuid)->toBe($uuid)
        ->and(SignedBuild::count())->toBe(1)
        ->and(Installation::sole()->signed_build_id)->toBe($build->id);
});

it('does not warm anything for visitors or devices Apple has not registered yet', function () {
    Queue::fake();
    $this->getJson("/api/v1/apps/{$this->catalogApp->public_id}")->assertOk();
    Queue::assertNotPushed(WarmBuildJob::class);
});

it('rebuilds a warmed app when its embedded profile is no longer valid', function (string $invalidity) {
    runnerHeartbeat()->assertOk();
    app(InstallationService::class)->prewarm($this->device, $this->catalogApp);
    runnerSigns($this);
    $build = SignedBuild::sole();
    $build->profile->forceFill($invalidity === 'revoked'
        ? ['status' => 'REVOKED']
        : ['expires_at' => now()->subMinute()])->save();

    expect($build->fresh()->isDeliverable())->toBeFalse();
    $installation = app(InstallationService::class)->prepare($this->customer, $this->device, $this->catalogApp);
    expect(SignedBuild::count())->toBe(2)
        ->and($installation->signed_build_id)->not->toBe($build->id);
})->with(['revoked', 'expired']);

it('promotes an unfinished background build when the customer asks to install it', function () {
    runnerHeartbeat()->assertOk();
    app(InstallationService::class)->prewarm($this->device, $this->catalogApp);
    $build = SignedBuild::sole();
    expect(PipelineJob::where('type', SigningService::RUNNER_JOB_TYPE)->sole()->payload['priority'])->toBe(10);

    app(InstallationService::class)->prepare($this->customer, $this->device, $this->catalogApp);
    expect(SignedBuild::count())->toBe(1)
        ->and(Installation::sole()->signed_build_id)->toBe($build->id)
        ->and(PipelineJob::where('type', SigningService::RUNNER_JOB_TYPE)->sole()->payload['priority'])->toBe(0);
});

it('moves a promoted build\'s waiting preparation from the background queue to the customer queue', function () {
    runnerHeartbeat()->assertOk();
    Queue::fake([PrepareSigningJob::class]);
    app(InstallationService::class)->prewarm($this->device, $this->catalogApp);
    Queue::assertPushedOn('background', PrepareSigningJob::class);

    app(InstallationService::class)->prepare($this->customer, $this->device, $this->catalogApp);
    Queue::assertPushedOn(PrepareSigningJob::QUEUE, PrepareSigningJob::class);
    expect(PipelineJob::where('type', PrepareSigningJob::TYPE)->sole()->payload['priority'])->toBe(0);

    // Whichever copy runs second finds the job taken and does nothing.
    $job = PipelineJob::where('type', PrepareSigningJob::TYPE)->sole();
    (new PrepareSigningJob($job->id))->onQueue(PrepareSigningJob::QUEUE)->handle(app(PipelineJobService::class));
    (new PrepareSigningJob($job->id))->onQueue('background')->handle(app(PipelineJobService::class));
    expect($job->fresh()->attempt)->toBe(1)
        ->and(PipelineJob::where('type', SigningService::RUNNER_JOB_TYPE)->count())->toBe(1);
});

it('warms only a bounded selection and skips incompatible or withdrawn apps', function () {
    runnerHeartbeat()->assertOk();
    config(['storefront.signing.warmup_popular_limit' => 1]);
    foreach (range(1, 2) as $n) {
        $other = CatalogApp::factory()->create(['visibility' => 'PUBLISHED']);
        $artifact = $this->artifact->replicate(['public_id']);
        $artifact->app_id = $other->id;
        $artifact->save();
    }
    Queue::fake();
    app(BuildWarmup::class)->forDevice($this->device);
    Queue::assertPushed(WarmBuildJob::class, 1);
    Queue::assertPushed(WarmBuildJob::class, fn ($job) => $job->queue === 'background');

    // A changed publication cannot be warmed by a stale queued job.
    $job = new WarmBuildJob($this->catalogApp->id, $this->artifact->id, $this->device->id);
    $this->artifact->forceFill(['status' => ArtifactStatus::Revoked])->save();
    $job->handle(app(InstallationService::class));
    expect(SignedBuild::count())->toBe(0);
});

it('starts popular builds when Apple registration completes, only once', function () {
    runnerHeartbeat()->assertOk();
    Queue::fake([WarmBuildJob::class]);
    $device = Device::factory()->make(['user_id' => $this->customer->id, 'device_family' => DeviceFamily::Iphone]);
    $device->setUdid('00008140-000000000000002D');
    $device->save();
    $service = app(DeviceRegistrationService::class);
    $registration = $service->request($device);
    $service->register($registration);
    $service->register($registration);
    Queue::assertPushed(WarmBuildJob::class, 1);
    Queue::assertPushed(WarmBuildJob::class, fn ($job) => $job->deviceId === $device->id && $job->artifactId === $this->artifact->id);
});

it('prioritizes customer signing and starts speculative jobs only on an idle runner', function () {
    // Per-device builds, so each device has a build of its own to lease.
    config(['storefront.signing.shared_builds' => false]);
    runnerHeartbeat()->assertOk();
    config(['storefront.signing.warmup_popular_limit' => 0]);
    $installations = app(InstallationService::class);
    $background = $installations->prewarm($this->device, $this->catalogApp);
    $builds = [];
    foreach (['00008140-000000000000002D', '00008140-000000000000003E'] as $udid) {
        $device = Device::factory()->make(['user_id' => $this->customer->id, 'device_family' => DeviceFamily::Iphone]);
        $device->setUdid($udid);
        $device->save();
        $service = app(DeviceRegistrationService::class);
        $service->register($service->request($device));
        $builds[] = $installations->prewarm($device->fresh(), $this->catalogApp);
    }
    $signing = app(SigningService::class);
    $signing->requestBuild($this->artifact, $builds[0]->device);
    $runner = $this->runner->fresh();
    expect($signing->lease($runner)['signed_build_id'])->toBe($builds[0]->public_id)
        // A customer build is signing: the second slot stays free for the next install.
        ->and($signing->lease($runner))->toBeNull();

    // Once that lease has ended, the runner is idle and takes one speculative build.
    $this->travel(SigningService::LEASE_SECONDS + 1)->seconds();
    expect($signing->lease($runner)['signed_build_id'])->toBe($background->public_id)
        ->and($signing->lease($runner))->toBeNull();
});

it('does not prewarm an incompatible app or a device that lost eligibility', function () {
    runnerHeartbeat()->assertOk();
    $this->device->forceFill(['os_version' => '1.0'])->save();
    (new WarmBuildJob($this->catalogApp->id, $this->artifact->id, $this->device->id))
        ->handle(app(InstallationService::class));
    expect(SignedBuild::count())->toBe(0);

    $this->device->latestRegistration->forceFill(['status' => 'APPLE_PENDING'])->save();
    Queue::fake();
    app(BuildWarmup::class)->forDevice($this->device->fresh());
    Queue::assertNotPushed(WarmBuildJob::class);
});

it('embeds a one-time login code in the storefront build and signs the customer in with it', function () {
    runnerHeartbeat();
    $this->catalogApp->forceFill(['is_storefront' => true])->save();

    // Preparing the storefront app mints a device-bound claim and stores it (encrypted) on the build.
    $installation = app(InstallationService::class)
        ->prepare($this->customer, $this->device, $this->catalogApp->refresh());
    $build = $installation->signedBuild;

    expect(StorefrontClaim::where('user_id', $this->customer->id)->where('device_id', $this->device->id)->count())->toBe(1)
        ->and($build->refresh()->bootstrap_claim_encrypted)->not->toBeNull();

    // The runner's lease carries that code, so the signed IPA embeds it in Info.plist.
    Sanctum::actingAs($this->customer);
    $lease = runnerSigns($this);
    expect($lease['bootstrap_claim'] ?? null)->toBe($build->refresh()->bootstrap_claim_encrypted);

    // Redeeming that embedded code signs in this customer, with no password — the zero-tap first launch.
    forgetGuards();
    $this->postJson('/api/v1/storefront/claims/redeem', ['code' => $lease['bootstrap_claim']])
        ->assertStatus(201)
        ->assertJsonPath('data.user.email', $this->customer->email);
});

it('does not embed a login code for an ordinary app', function () {
    runnerHeartbeat();
    Sanctum::actingAs($this->customer);
    $installation = app(InstallationService::class)
        ->prepare($this->customer, $this->device, $this->catalogApp);

    expect($installation->signedBuild->refresh()->bootstrap_claim_encrypted)->toBeNull();
    $lease = runnerSigns($this);
    expect($lease)->not->toHaveKey('bootstrap_claim');
});

it('imports an IPA from Files, keeps it private to its owner, and makes it installable', function () {
    runnerHeartbeat();
    $bytes = IpaBuilder::app('com.vendor.importme')->info(['CFBundleDisplayName' => 'Imported One'])->build();
    Sanctum::actingAs($this->customer);

    $start = $this->postJson('/api/v1/imports', [
        'filename' => 'My Cool App.ipa', 'size_bytes' => strlen($bytes), 'declaration_accepted' => true,
    ])->assertCreated()->json('data');
    $this->call('PUT', "/api/v1/imports/{$start['id']}/chunks/0", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json',
    ], $bytes)->assertOk();
    $complete = $this->postJson("/api/v1/imports/{$start['id']}/complete")->assertCreated()->json('data');
    expect($complete['status'])->toBe('PROVENANCE_REVIEW');

    $importId = $start['import_id'];
    $app = CatalogApp::where('public_id', $importId)->sole();
    expect($app->imported_by_user_id)->toBe($this->customer->id)
        ->and($app->visibility->value)->toBe('HIDDEN')
        ->and($app->bundle_identifier)->toStartWith('com.ruappstore.');

    // Listed for its owner, with the name read from the bundle...
    $this->getJson('/api/v1/imports')->assertOk()
        ->assertJsonPath('data.0.id', $importId)
        ->assertJsonPath('data.0.name', 'Imported One');
    // ...never in the public catalog.
    $catalog = collect($this->getJson('/api/v1/apps')->assertOk()->json('data'));
    expect($catalog->pluck('id'))->not->toContain($importId);

    // Install: approve + publish + prepare a signed build for this device.
    $this->postJson("/api/v1/imports/{$importId}/install")->assertStatus(202)->assertJsonPath('data.status', 'PREPARING');
    $app->refresh();
    expect($app->publishedArtifact()->exists())->toBeTrue()
        ->and(SignedBuild::whereHas('artifact', fn ($q) => $q->where('app_id', $app->id))->exists())->toBeTrue();
});

it("does not let another customer see or install someone else's import", function () {
    $bytes = IpaBuilder::app()->build();
    Sanctum::actingAs($this->customer);
    $importId = $this->postJson('/api/v1/imports', ['filename' => 'A.ipa', 'size_bytes' => strlen($bytes), 'declaration_accepted' => true])
        ->assertCreated()->json('data.import_id');

    Sanctum::actingAs(subscribedCustomer());
    $this->postJson("/api/v1/imports/{$importId}/install")->assertNotFound();
    $this->getJson('/api/v1/imports')->assertOk()->assertJsonCount(0, 'data');
});

it('caps how many imports a customer can start', function () {
    config(['storefront.imports.daily_limit' => 1]);
    $bytes = IpaBuilder::app()->build();
    Sanctum::actingAs($this->customer);
    $this->postJson('/api/v1/imports', ['filename' => 'A.ipa', 'size_bytes' => strlen($bytes), 'declaration_accepted' => true])->assertCreated();
    $this->postJson('/api/v1/imports', ['filename' => 'B.ipa', 'size_bytes' => strlen($bytes), 'declaration_accepted' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'QUOTA_EXHAUSTED');
});

it('imports an IPA from a link: the server downloads it, inspects it, and the owner can install it', function () {
    runnerHeartbeat();
    $bytes = IpaBuilder::app('com.vendor.linked')->info(['CFBundleDisplayName' => 'Linked App'])->build();
    $fetcher = new FakeLinkFetcher([
        ['location' => 'https://cdn.files.example/blob/123'],
        ['body' => $bytes, 'disposition' => 'attachment; filename="Linked.ipa"'],
    ]);
    app()->instance(LinkFetcher::class, $fetcher);
    Sanctum::actingAs($this->customer);

    $importId = $this->postJson('/api/v1/imports/link', ['url' => 'https://files.example/s/Linked.ipa', 'declaration_accepted' => true])
        ->assertStatus(202)->json('data.id');

    // The queue runs synchronously in tests: downloaded, uploaded and inspected already.
    $list = $this->getJson('/api/v1/imports');
    expect(OpenApiContract::errors($list->getContent(), 'ImportListResponse'))->toBe([]);
    $list->assertOk()
        ->assertJsonPath('data.0.id', $importId)
        ->assertJsonPath('data.0.name', 'Linked App')
        ->assertJsonPath('data.0.status', 'PROVENANCE_REVIEW')
        ->assertJsonPath('data.0.installable', true);
    expect($fetcher->requested)->toBe(['https://files.example/s/Linked.ipa', 'https://cdn.files.example/blob/123']);

    $this->postJson("/api/v1/imports/{$importId}/install")->assertStatus(202)->assertJsonPath('data.status', 'PREPARING');
});

it('shows why a link import failed and does not retry a link that can never work', function () {
    app()->instance(LinkFetcher::class, new FakeLinkFetcher([['body' => '<html>login</html>']]));
    Sanctum::actingAs($this->customer);

    $this->postJson('/api/v1/imports/link', ['url' => 'https://files.example/page', 'declaration_accepted' => true])->assertStatus(202);

    $this->getJson('/api/v1/imports')->assertOk()
        ->assertJsonPath('data.0.status', 'DOWNLOAD_FAILED')
        ->assertJsonPath('data.0.installable', false)
        ->assertJsonPath('data.0.failure_reason', 'По ссылке не файл IPA. Нужна прямая ссылка на скачивание.');
    expect(PipelineJob::where('type', 'FetchImportJob')->sole()->attempt)->toBe(1);
});

it('refuses a link to a private address before starting an import', function () {
    app()->instance(LinkFetcher::class, new FakeLinkFetcher([]));
    Sanctum::actingAs($this->customer);

    $this->postJson('/api/v1/imports/link', ['url' => 'http://169.254.169.254/latest/meta-data', 'declaration_accepted' => true])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect(CatalogApp::where('imported_by_user_id', $this->customer->id)->count())->toBe(0);
});

it('lets two customers import the same file', function () {
    $bytes = IpaBuilder::app('com.vendor.shared')->build();
    foreach ([$this->customer, subscribedCustomer()] as $customer) {
        Sanctum::actingAs($customer);
        $start = $this->postJson('/api/v1/imports', ['filename' => 'Shared.ipa', 'size_bytes' => strlen($bytes), 'declaration_accepted' => true])
            ->assertCreated()->json('data');
        $this->call('PUT', "/api/v1/imports/{$start['id']}/chunks/0", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json',
        ], $bytes)->assertOk();
        $this->postJson("/api/v1/imports/{$start['id']}/complete")->assertCreated()->assertJsonPath('data.status', 'PROVENANCE_REVIEW');
    }
});

it('deletes an import, frees its file and its slot, but still counts it toward today', function () {
    config(['storefront.imports.total_limit' => 1, 'storefront.imports.daily_limit' => 2]);
    $bytes = IpaBuilder::app('com.vendor.deleteme')->build();
    Sanctum::actingAs($this->customer);
    $start = $this->postJson('/api/v1/imports', ['filename' => 'Del.ipa', 'size_bytes' => strlen($bytes), 'declaration_accepted' => true])
        ->assertCreated()->json('data');
    $this->call('PUT', "/api/v1/imports/{$start['id']}/chunks/0", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json',
    ], $bytes)->assertOk();
    $this->postJson("/api/v1/imports/{$start['id']}/complete")->assertCreated();
    $artifact = AppArtifact::whereHas('app', fn ($q) => $q->where('public_id', $start['import_id']))->sole();
    Storage::disk('artifacts')->assertExists($artifact->storage_path);

    // Another customer cannot delete it.
    Sanctum::actingAs(subscribedCustomer());
    $this->deleteJson("/api/v1/imports/{$start['import_id']}")->assertNotFound();

    Sanctum::actingAs($this->customer);
    $this->deleteJson("/api/v1/imports/{$start['import_id']}")->assertOk();
    $this->getJson('/api/v1/imports')->assertOk()->assertJsonCount(0, 'data');
    Storage::disk('artifacts')->assertMissing($artifact->storage_path);
    expect($artifact->refresh()->purged_at)->not->toBeNull();

    // The total slot is free again; the daily cap (2) still counts the deleted one.
    $this->postJson('/api/v1/imports', ['filename' => 'B.ipa', 'size_bytes' => 10, 'declaration_accepted' => true])->assertCreated();
    $this->deleteJson('/api/v1/imports/'.CatalogApp::where('imported_by_user_id', $this->customer->id)->sole()->public_id)->assertOk();
    $this->postJson('/api/v1/imports', ['filename' => 'C.ipa', 'size_bytes' => 10, 'declaration_accepted' => true])
        ->assertStatus(409)->assertJsonPath('error.code', 'QUOTA_EXHAUSTED');
});

describe('builds shared by the Apple team', function () {
    /** Another iPhone of the customer, registered with the team. */
    function teamDevice(object $test, string $udid): Device
    {
        $device = Device::factory()->make(['user_id' => $test->customer->id, 'device_family' => DeviceFamily::Iphone]);
        $device->setUdid($udid);
        $device->save();
        $service = app(DeviceRegistrationService::class);
        $service->register($service->request($device));

        return $device->fresh();
    }

    /** The runner signs whatever it leases next, embedding that build's own profile. */
    function signNextLease(): SignedBuild
    {
        $lease = worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->json('data');
        expect($lease)->not->toBeNull();
        $build = SignedBuild::where('public_id', $lease['signed_build_id'])->sole();
        $signed = signedIpa($build->profile);
        worker('PUT', $lease['upload_path'], $signed)->assertCreated();
        worker('POST', $lease['result_path'], json_encode(['status' => 'succeeded', 'sha256' => hash('sha256', $signed), 'report' => ['codesign' => 'valid']]))->assertOk();

        return $build->fresh();
    }

    it('serves a second device of the team at once from the build signed for the first', function () {
        runnerHeartbeat()->assertOk();
        $second = teamDevice($this, '00008140-000000000000002D');
        $installations = app(InstallationService::class);

        $first = $installations->prepare($this->customer, $this->device, $this->catalogApp);
        $build = signNextLease();
        expect($first->fresh()->status)->toBe(InstallationStatus::ReadyToInstall)
            ->and($build->isShared())->toBeTrue()
            ->and($build->profile->covers($second))->toBeTrue();

        // No new profile, no new signing: the second iPhone is ready immediately.
        $again = $installations->prepare($this->customer, $second, $this->catalogApp);
        expect($again->status)->toBe(InstallationStatus::ReadyToInstall)
            ->and($again->signed_build_id)->toBe($build->id)
            ->and(SignedBuild::count())->toBe(1)
            ->and(SigningProfile::count())->toBe(1)
            ->and($installations->authorize($again, $second, null)['install_url'])->toStartWith('itms-services://');
    });

    it('signs again for a device that joined later, and the older build keeps serving its devices', function () {
        runnerHeartbeat()->assertOk();
        $installations = app(InstallationService::class);
        $installations->prepare($this->customer, $this->device, $this->catalogApp);
        $older = signNextLease();

        $late = teamDevice($this, '00008140-000000000000003E');
        expect($older->serves($late))->toBeFalse();
        $installation = $installations->prepare($this->customer, $late, $this->catalogApp);
        expect($installation->status)->toBe(InstallationStatus::Preparing);
        $newer = signNextLease();

        expect($installation->fresh()->status)->toBe(InstallationStatus::ReadyToInstall)
            ->and($newer->id)->not->toBe($older->id)
            ->and($newer->profile->covers($late))->toBeTrue()
            ->and($newer->profile->covers($this->device))->toBeTrue()
            // Both device sets keep their profile; the older build is still installable.
            ->and(SigningProfile::count())->toBe(2)
            ->and($older->fresh()->isDeliverable())->toBeTrue()
            ->and($older->fresh()->serves($this->device))->toBeTrue();
    });

    it('rejects a shared build whose profile does not list every device it was made for', function () {
        runnerHeartbeat()->assertOk();
        $other = teamDevice($this, '00008140-000000000000004F');
        app(InstallationService::class)->prepare($this->customer, $this->device, $this->catalogApp);
        $lease = worker('POST', '/api/worker/v1/leases', '{}')->assertOk()->json('data');
        $build = SignedBuild::where('public_id', $lease['signed_build_id'])->sole();
        $signed = signedIpa($build->profile);
        // The profile row now claims a device the embedded profile does not list.
        $stranger = teamDevice($this, '00008140-000000000000005A');
        $build->profile->forceFill(['device_ids' => [...$build->profile->device_ids, $stranger->id]])->save();
        worker('PUT', $lease['upload_path'], $signed)->assertCreated();
        worker('POST', $lease['result_path'], json_encode(['status' => 'succeeded', 'sha256' => hash('sha256', $signed), 'report' => ['codesign' => 'valid']]))->assertOk();

        expect($build->fresh()->status)->toBe(SignedBuildStatus::ValidationFailed)
            ->and($build->fresh()->status_reason)->toBe('DEVICE_NOT_IN_PROFILE')
            ->and($build->profile->covers($other))->toBeTrue();
    });

    it('keeps the most installed apps signed for every current device of the team', function () {
        runnerHeartbeat()->assertOk();
        $warmup = app(BuildWarmup::class);
        // A ready build of the device's own (signed before sharing) does not serve the team.
        config(['storefront.signing.shared_builds' => false]);
        app(InstallationService::class)->prepare($this->customer, $this->device, $this->catalogApp);
        expect(signNextLease()->isShared())->toBeFalse();
        config(['storefront.signing.shared_builds' => true]);

        expect($warmup->forTeams())->toBe(1)
            // Already on its way for this device set: nothing new.
            ->and($warmup->forTeams())->toBe(0);
        $build = signNextLease();
        expect($build->isShared())->toBeTrue()
            ->and($build->installations()->exists())->toBeFalse()
            ->and($warmup->forTeams())->toBe(0);

        // A device joins (its own registration warm-up held back): the build does not list it,
        // so the app is signed again for the new device set.
        Queue::fake([WarmBuildJob::class]);
        teamDevice($this, '00008140-000000000000006B');
        expect($warmup->forTeams())->toBe(1);

        config(['storefront.signing.team_presign_limit' => 0]);
        expect($warmup->forTeams())->toBe(0);
    });

    it('signs for each device alone when sharing is switched off', function () {
        config(['storefront.signing.shared_builds' => false]);
        runnerHeartbeat()->assertOk();
        $installation = app(InstallationService::class)->prepare($this->customer, $this->device, $this->catalogApp);

        expect($installation->signedBuild->device_id)->toBe($this->device->id)
            ->and(SigningProfile::sole()->device_id)->toBe($this->device->id);
    });
});
