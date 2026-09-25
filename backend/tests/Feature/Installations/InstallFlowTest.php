<?php

use App\Enums\ArtifactStatus;
use App\Enums\InstallationStatus;
use App\Enums\PipelineJobStatus;
use App\Enums\RoleSlug;
use App\Enums\SignedBuildStatus;
use App\Http\Middleware\VerifyWorkerSignature;
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
use App\Services\Signing\SigningService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IpaBuilder;
use Tests\Support\OpenApiContract;
use Tests\Support\ScriptedApple;

const RUNNER_SECRET = 'test-runner-secret-0123456789abcdef';
const CERT_SHA1 = 'A1B2C3D4E5F60718293A4B5C6D7E8F9012345678';

beforeEach(function () {
    Storage::fake('artifacts');
    $this->team = connectFakeAppleTeam();

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

    // PrepareSigningJob provisioned an ad hoc profile for (team, bundle, device) and queued the runner job.
    $profile = SigningProfile::sole();
    expect($profile->bundle_identifier)->toBe('com.example.demo')
        ->and($profile->device_id)->toBe($this->device->id)
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

    // A second request for the same build on the same device reuses it: no second signing.
    $this->withToken($token)->getJson("/api/v1/apps/{$this->catalogApp->public_id}")->assertJsonPath('data.install_state.status', 'delivered');
    $library = $this->withToken($token)->getJson('/api/v1/library')->assertOk()->assertJsonPath('data.0.status', 'DELIVERED');
    expect(OpenApiContract::errors($library->getContent(), 'LibraryResponse'))->toBe([]);
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
    expect(Installation::count())->toBe(0);
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
