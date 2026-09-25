<?php

use App\Enums\ArtifactStatus;
use App\Enums\PipelineJobStatus;
use App\Enums\RoleSlug;
use App\Jobs\InspectArtifactJob;
use App\Models\AppArtifact;
use App\Models\AppVersion;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\PipelineJob;
use App\Models\User;
use App\Services\Artifacts\ArtifactInspectionService;
use App\Services\Pipeline\PipelineJobService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IpaBuilder;
use Tests\Support\OpenApiContract;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->manager = userWithRoles(RoleSlug::CatalogManager);
    $this->catalogApp = CatalogApp::factory()->create();
});

/**
 * Uploads bytes through the chunked API; the sync queue inspects them inline.
 *
 * @return array<string, mixed> The complete response's data.
 */
function uploadIpa(User $manager, CatalogApp $app, string $bytes, ?AppVersion $version = null): array
{
    $upload = asStaff($manager)->postJson('/api/v1/admin/uploads', [
        'app_id' => $app->public_id,
        'app_version_id' => $version?->public_id,
        'filename' => 'DemoApp.ipa',
        'size_bytes' => strlen($bytes),
        'source_type' => 'OWN_BUILD',
        'declaration_version' => '2026-09-v1',
        'declaration_accepted' => true,
    ])->assertCreated()->json('data');

    test()->call('PUT', "/api/v1/admin/uploads/{$upload['id']}/chunks/0", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_REFERER' => 'http://localhost',
    ], $bytes)->assertOk();

    $response = test()->postJson("/api/v1/admin/uploads/{$upload['id']}/complete")->assertCreated();
    expect(OpenApiContract::errors($response->getContent(), 'UploadedArtifactResponse'))->toBe([]);

    return $response->json('data');
}

function inspected(array $data): AppArtifact
{
    return AppArtifact::where('public_id', $data['id'])->sole();
}

it('moves a valid IPA to provenance review with its metadata and an audited job', function () {
    $data = uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->build());

    expect($data['status'])->toBe('PROVENANCE_REVIEW')->and($data['job_id'])->not->toBeNull();

    $artifact = inspected($data);
    expect($artifact->bundle_identifier)->toBe('com.example.demo')
        ->and($artifact->version)->toBe('1.0.0')
        ->and($artifact->build_number)->toBe('42')
        ->and($artifact->min_ios_version)->toBe('18.0')
        ->and($artifact->status_reason)->toBeNull()
        ->and($artifact->inspection['binaries'][0])->toMatchArray([
            'role' => 'main', 'architectures' => ['arm64'], 'encrypted' => false, 'platform' => 'ios', 'min_os' => '18.0',
        ])
        ->and($artifact->inspection['entitlements'])->toEqual(['application-identifier' => 'ABCDE12345.com.example.demo', 'get-task-allow' => false])
        ->and($artifact->inspection['compatibility_issues'])->toBe([])
        ->and($artifact->inspection['malware_scan']['status'])->toBe('SCAN_UNAVAILABLE')
        ->and($artifact->inspection['embedded_profile'])->toBeNull();

    $job = PipelineJob::where('public_id', $data['job_id'])->sole();
    expect($job->status)->toBe(PipelineJobStatus::Succeeded)
        ->and($job->result_code)->toBe('INSPECTED')
        ->and($job->attempt)->toBe(1)
        ->and($job->subject->is($artifact))->toBeTrue()
        ->and($job->attempts()->sole()->result_code)->toBe('INSPECTED');

    $artifactStates = AuditLog::where('action', 'app_artifact.status_changed')->orderBy('id')->pluck('after')->pluck('status')->all();
    expect($artifactStates)->toBe(['HASHING', 'INSPECTING', 'PROVENANCE_REVIEW'])
        ->and(AuditLog::where('action', 'artifact.inspected')->sole()->after['outcome'])->toBe('PROVENANCE_REVIEW');
});

it('reads binary Info.plist files and universal binaries', function () {
    $ipa = IpaBuilder::app()
        ->binaryInfoPlist()
        ->executable(IpaBuilder::fat([IpaBuilder::machO(IpaBuilder::X86_64), IpaBuilder::machO()]))
        ->withExtension('Share', 'com.example.demo.share')
        ->withFramework('Kit')
        ->build();

    $artifact = inspected(uploadIpa($this->manager, $this->catalogApp, $ipa));

    expect($artifact->status)->toBe(ArtifactStatus::ProvenanceReview)
        ->and($artifact->inspection['binaries'][0]['architectures'])->toBe(['x86_64', 'arm64'])
        ->and(array_column($artifact->inspection['nested_bundles'], 'type'))->toBe(['framework', 'extension'])
        ->and(array_column($artifact->inspection['binaries'], 'role'))->toBe(['main', 'framework', 'extension']);
});

it('rejects encrypted binaries anywhere in the app', function (IpaBuilder $builder, string $path) {
    $artifact = inspected(uploadIpa($this->manager, $this->catalogApp, $builder->build()));

    expect($artifact->status)->toBe(ArtifactStatus::Rejected)
        ->and($artifact->status_reason)->toBe('ENCRYPTED_BINARY')
        ->and($artifact->inspection['failure']['details']['path'])->toBe($path);
})->with([
    'main executable' => fn () => [IpaBuilder::app()->executable(IpaBuilder::machO(cryptId: 1)), 'Payload/Demo.app/Demo'],
    'one slice of a universal binary' => fn () => [
        IpaBuilder::app()->executable(IpaBuilder::fat([IpaBuilder::machO(), IpaBuilder::machO(IpaBuilder::X86_64, cryptId: 1)])),
        'Payload/Demo.app/Demo',
    ],
    'app extension' => fn () => [IpaBuilder::app()->withExtension('Share', 'com.example.demo.share', cryptId: 1), 'Payload/Demo.app/PlugIns/Share.appex/Share'],
    'framework' => fn () => [IpaBuilder::app()->withFramework('Kit', cryptId: 1), 'Payload/Demo.app/Frameworks/Kit.framework/Kit'],
]);

it('fails unsafe archives without extracting them', function (string $bytes, string $code) {
    $artifact = inspected(uploadIpa($this->manager, $this->catalogApp, $bytes));

    expect($artifact->status)->toBe(ArtifactStatus::InspectionFailed)
        ->and($artifact->status_reason)->toBe($code);
})->with([
    'not a zip' => fn () => ["PK\x03\x04 truncated", 'INVALID_ARCHIVE'],
    'parent path' => fn () => [IpaBuilder::app()->build(['../evil.sh' => 'x']), 'UNSAFE_PATH'],
    'nested parent path' => fn () => [IpaBuilder::app()->withAppFile('../../../tmp/evil', 'x')->build(), 'UNSAFE_PATH'],
    'absolute path' => fn () => [IpaBuilder::app()->build(['/etc/evil' => 'x']), 'UNSAFE_PATH'],
    'symlink out of the app' => fn () => [IpaBuilder::app()->withSymlink('link', '../../../../etc/passwd')->build(), 'UNSAFE_SYMLINK'],
    'case-folded duplicate' => fn () => [IpaBuilder::app()->withAppFile('info.plist', 'x')->build(), 'DUPLICATE_ENTRY'],
    'file outside Payload' => fn () => [IpaBuilder::app()->build(['readme.txt' => 'x']), 'UNEXPECTED_ENTRY'],
    'two apps' => fn () => [IpaBuilder::app()->build(['Payload/Other.app/Info.plist' => 'x']), 'MULTIPLE_APP_BUNDLES'],
    'no app' => fn () => [(function () {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('Payload/readme.txt', 'x');
        $zip->close();

        return file_get_contents($path);
    })(), 'NO_APP_BUNDLE'],
    'missing bundle ID' => fn () => [IpaBuilder::app()->withoutInfoKey('CFBundleIdentifier')->build(), 'INVALID_METADATA'],
    'garbage Info.plist' => fn () => [IpaBuilder::app()->build(['Payload/Demo.app/Info.plist' => 'not a plist']), 'INVALID_INFO_PLIST'],
    'executable is not Mach-O' => fn () => [IpaBuilder::app()->executable('#!/bin/sh')->build(), 'INVALID_EXECUTABLE'],
    'truncated Mach-O' => fn () => [IpaBuilder::app()->executable(substr(IpaBuilder::machO(), 0, 60))->build(), 'INVALID_EXECUTABLE'],
    'missing executable' => fn () => [IpaBuilder::app()->info(['CFBundleExecutable' => 'Missing'])->build(), 'EXECUTABLE_MISSING'],
]);

it('stops a zip bomb by its compression ratio', function () {
    config(['storefront.inspection.ratio_min_bytes' => 1024, 'storefront.inspection.max_compression_ratio' => 20]);

    $artifact = inspected(uploadIpa(
        $this->manager,
        $this->catalogApp,
        IpaBuilder::app()->withAppFile('padding.bin', str_repeat("\0", 512 * 1024))->build(),
    ));

    expect($artifact->status)->toBe(ArtifactStatus::InspectionFailed)
        ->and($artifact->status_reason)->toBe('COMPRESSION_RATIO');
});

it('records compatibility issues for the review without failing inspection', function () {
    $ipa = IpaBuilder::app()
        ->executable(IpaBuilder::machO(IpaBuilder::X86_64, platform: 7))
        ->info(['UIDeviceFamily' => [2]])
        ->withExtension('Share', 'com.other.share')
        ->build();

    $artifact = inspected(uploadIpa($this->manager, $this->catalogApp, $ipa));

    expect($artifact->status)->toBe(ArtifactStatus::ProvenanceReview)
        ->and(array_column($artifact->inspection['compatibility_issues'], 'code'))
        ->toBe(['ARM64_MISSING', 'SIMULATOR_BUILD', 'IPHONE_UNSUPPORTED', 'NESTED_BUNDLE_ID_MISMATCH']);
});

it('summarises the embedded profile without storing device identifiers', function () {
    $udid = '00008030-001A2B3C4D5E6F70';
    $ipa = IpaBuilder::app()->withProfile([
        'Name' => 'Demo Ad Hoc',
        'TeamIdentifier' => ['ABCDE12345'],
        'AppIDName' => 'Demo',
        'ProvisionedDevices' => [$udid, '00008101-000000000000001E'],
        'Entitlements' => ['get-task-allow' => false],
        'ExpirationDate' => new DateTime('2030-01-01T00:00:00Z'),
    ])->build();

    $artifact = inspected(uploadIpa($this->manager, $this->catalogApp, $ipa));

    expect($artifact->inspection['embedded_profile'])->toMatchArray([
        'readable' => true,
        'name' => 'Demo Ad Hoc',
        'type' => 'AD_HOC',
        'team_identifier' => 'ABCDE12345',
        'expires_at' => '2030-01-01T00:00:00Z',
        'expired' => false,
        'provisioned_device_count' => 2,
    ])->and(json_encode($artifact->inspection))->not->toContain($udid);
});

it('rejects a version that already exists and a different bundle ID for the same app', function () {
    uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->build());

    $duplicate = inspected(uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->info(['CFBundleName' => 'Rebuilt'])->build()));
    expect($duplicate->status)->toBe(ArtifactStatus::Rejected)->and($duplicate->status_reason)->toBe('VERSION_EXISTS');

    $otherBundle = inspected(uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app('com.example.other')->version('2.0', '1')->build()));
    expect($otherBundle->status)->toBe(ArtifactStatus::InspectionFailed)->and($otherBundle->status_reason)->toBe('BUNDLE_ID_MISMATCH');

    $next = inspected(uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->version('1.1.0', '43')->build()));
    expect($next->status)->toBe(ArtifactStatus::ProvenanceReview);
});

it('checks the IPA against the catalog version it was uploaded for', function () {
    $version = AppVersion::factory()->for($this->catalogApp, 'app')->create(['version' => '2.0.0', 'build_number' => '7']);

    $artifact = inspected(uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->build(), $version));

    expect($artifact->status)->toBe(ArtifactStatus::InspectionFailed)
        ->and($artifact->status_reason)->toBe('VERSION_MISMATCH');
});

it('quarantines files the malware scanner flags', function () {
    $scanner = tempnam(sys_get_temp_dir(), 'clamdscan');
    file_put_contents($scanner, "#!/bin/sh\necho \"\$3: Eicar-Test-Signature FOUND\"\nexit 1\n");
    chmod($scanner, 0700);
    config(['storefront.inspection.clamdscan_path' => $scanner]);

    try {
        $artifact = inspected(uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->build()));
    } finally {
        unlink($scanner);
    }

    expect($artifact->status)->toBe(ArtifactStatus::Quarantined)
        ->and($artifact->status_reason)->toBe('MALWARE_DETECTED')
        ->and($artifact->inspection['failure']['details']['signature'])->toBe('Eicar-Test-Signature');
});

it('rejects a stored file whose hash no longer matches', function () {
    Queue::fake();
    $data = uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->build());
    $artifact = inspected($data);
    Storage::disk('artifacts')->put($artifact->storage_path, 'tampered');

    (new InspectArtifactJob(PipelineJob::where('public_id', $data['job_id'])->sole()->id))->handle(
        app(PipelineJobService::class),
        app(ArtifactInspectionService::class),
    );

    expect($artifact->refresh()->status)->toBe(ArtifactStatus::Rejected)
        ->and($artifact->status_reason)->toBe('UPLOAD_CORRUPT');
});

it('retries failed attempts, then fails permanently, and never runs a finished job twice', function () {
    Queue::fake();
    $data = uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->build());
    $job = PipelineJob::where('public_id', $data['job_id'])->sole();
    Queue::assertPushed(InspectArtifactJob::class, fn (InspectArtifactJob $queued) => $queued->pipelineJobId === $job->id);

    $this->mock(ArtifactInspectionService::class)
        ->shouldReceive('inspect')->times(3)->andThrow(new RuntimeException('disk unavailable'));

    foreach ([PipelineJobStatus::FailedRetryable, PipelineJobStatus::FailedRetryable, PipelineJobStatus::FailedPermanent] as $expected) {
        app()->call([new InspectArtifactJob($job->id), 'handle']);
        expect($job->refresh()->status)->toBe($expected);
    }

    expect($job->attempt)->toBe(3)
        ->and($job->error_class)->toBe(RuntimeException::class)
        ->and($job->attempts()->count())->toBe(3);

    // A permanently failed job waits for an operator; the queue does not pick it up again.
    app()->call([new InspectArtifactJob($job->id), 'handle']);
    expect($job->refresh()->attempt)->toBe(3);
});
