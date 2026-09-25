<?php

use App\Enums\ArtifactStatus;
use App\Enums\PipelineJobStatus;
use App\Enums\RoleSlug;
use App\Models\AppArtifact;
use App\Models\ArtifactReview;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\PipelineJob;
use App\Models\TeamAppEligibility;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\IpaBuilder;
use Tests\Support\OpenApiContract;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->manager = userWithRoles(RoleSlug::CatalogManager);
    $this->catalogApp = CatalogApp::factory()->create();
    approveTeamFor('com.example.demo');
    forgetGuards();
});

const CHECKLIST = ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true];

function inReview(object $test, ?IpaBuilder $ipa = null, string $sourceType = 'OWN_BUILD'): AppArtifact
{
    $artifact = inspected(uploadIpa($test->manager, $test->catalogApp, ($ipa ?? IpaBuilder::app())->build(), sourceType: $sourceType));
    expect($artifact->status)->toBe(ArtifactStatus::ProvenanceReview);

    return $artifact;
}

function matchesContract(TestResponse $response, string $schema): TestResponse
{
    expect(OpenApiContract::errors($response->getContent(), $schema))->toBe([], $schema);

    return $response;
}

function approve(AppArtifact $artifact, array $extra = []): TestResponse
{
    return test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/review", $extra + [
        'decision' => 'approve',
        'checklist' => CHECKLIST,
        'acknowledge_scan_result' => true,
    ]);
}

it('approves a reviewed artifact through the compatibility check to READY', function () {
    $artifact = inReview($this);

    matchesContract(approve($artifact, ['reason' => 'Our own build']), 'AdminArtifactReviewResponse')
        ->assertOk()
        ->assertJsonPath('data.decision', 'APPROVED')
        ->assertJsonPath('data.artifact.status', 'READY');

    $artifact->refresh();
    $review = ArtifactReview::sole();
    expect($review->from_status)->toBe('PROVENANCE_REVIEW')
        ->and($review->to_status)->toBe('COMPATIBILITY_CHECK')
        ->and($review->checklist)->toEqual(CHECKLIST)
        ->and($review->scan_result_acknowledged)->toBeTrue()
        ->and($artifact->inspection['compatibility']['blocking'])->toBe([])
        ->and($artifact->inspection['compatibility']['warnings'])->toBe([]);

    $states = AuditLog::where('action', 'app_artifact.status_changed')->orderBy('id')->pluck('after')->pluck('status')->all();
    expect(array_slice($states, -2))->toBe(['COMPATIBILITY_CHECK', 'READY']);
});

it('requires the full checklist and an acknowledged scan result to approve', function () {
    $artifact = inReview($this);

    approve($artifact, ['checklist' => ['source_verified' => true]])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonPath('error.details.missing', ['distribution_rights_confirmed', 'inspection_report_reviewed']);

    approve($artifact, ['acknowledge_scan_result' => false])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.acknowledge_scan_result.0', 'The malware scan did not report this file as clean.');

    expect($artifact->refresh()->status)->toBe(ArtifactStatus::ProvenanceReview)
        ->and(ArtifactReview::count())->toBe(0);
});

it('rejects a review with a reason', function () {
    $artifact = inReview($this);

    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/review", ['decision' => 'reject'])
        ->assertUnprocessable();

    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/review", ['decision' => 'reject', 'reason' => 'No distribution rights'])
        ->assertOk()
        ->assertJsonPath('data.artifact.status', 'PROVENANCE_FAILED')
        ->assertJsonPath('data.artifact.status_reason', 'REVIEW_REJECTED');

    approve($artifact)->assertStatus(409)->assertJsonPath('error.code', 'ILLEGAL_STATE_TRANSITION');
});

it('rejects approved artifacts that can never be installed', function (Closure $setup, string $code) {
    [$ipa, $sourceType] = $setup();
    $artifact = inReview($this, $ipa, $sourceType);

    approve($artifact)->assertOk()->assertJsonPath('data.artifact.status', 'REJECTED');

    expect($artifact->refresh()->status_reason)->toBe($code);
})->with([
    'no arm64 slice' => [fn () => [IpaBuilder::app()->executable(IpaBuilder::machO(IpaBuilder::X86_64)), 'OWN_BUILD'], 'ARM64_MISSING'],
    'simulator build' => [fn () => [IpaBuilder::app()->executable(IpaBuilder::machO(platform: 7)), 'OWN_BUILD'], 'SIMULATOR_BUILD'],
    'source type differs from the listing' => [fn () => [IpaBuilder::app(), 'PARTNER_BUILD'], 'SOURCE_TYPE_MISMATCH'],
]);

it('blocks bundle IDs no Apple team is approved to distribute', function () {
    TeamAppEligibility::query()->delete();
    $artifact = inReview($this);

    approve($artifact)->assertOk()->assertJsonPath('data.artifact.status', 'REJECTED');
    expect($artifact->refresh()->status_reason)->toBe('TEAM_NOT_ELIGIBLE');
});

it('blocks source types the compliance decision does not allow', function () {
    config(['storefront.artifacts.publishable_source_types' => ['PARTNER_BUILD']]);
    $artifact = inReview($this);

    approve($artifact)->assertOk()->assertJsonPath('data.artifact.status', 'REJECTED');
    expect($artifact->refresh()->status_reason)->toBe('SOURCE_TYPE_NOT_PUBLISHABLE');
});

it('publishes a READY artifact, links its version and supersedes the previous build', function () {
    $first = inReview($this);
    approve($first)->assertOk();

    matchesContract($this->postJson("/api/v1/admin/artifacts/{$first->public_id}/publish", [], ['Idempotency-Key' => 'publish-first-0001']), 'AdminArtifactResponse')
        ->assertOk()
        ->assertJsonPath('data.status', 'PUBLISHED');
    // A retried request with the same key replays the first answer instead of failing.
    $this->postJson("/api/v1/admin/artifacts/{$first->public_id}/publish", [], ['Idempotency-Key' => 'publish-first-0001'])
        ->assertOk()
        ->assertJsonPath('data.status', 'PUBLISHED');

    $first->refresh();
    expect($first->appVersion->version)->toBe('1.0.0')
        ->and($first->appVersion->build_number)->toBe('42')
        ->and($first->appVersion->released_at)->not->toBeNull()
        ->and($this->catalogApp->publishedArtifact()->first()?->is($first))->toBeTrue();

    $second = inReview($this, IpaBuilder::app()->version('1.1.0', '43'));
    approve($second)->assertOk();
    $this->postJson("/api/v1/admin/artifacts/{$second->public_id}/publish")->assertOk();

    expect($first->refresh()->status)->toBe(ArtifactStatus::Expired)
        ->and($first->status_reason)->toBe('SUPERSEDED')
        ->and($this->catalogApp->publishedArtifact()->first()?->is($second))->toBeTrue()
        ->and($this->catalogApp->versions()->count())->toBe(2)
        ->and(AuditLog::where('action', 'artifact.published')->count())->toBe(2);
});

it('only publishes READY artifacts with a publishable source type', function () {
    $artifact = inReview($this);

    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/publish")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ILLEGAL_STATE_TRANSITION');

    approve($artifact)->assertOk();
    config(['storefront.artifacts.publishable_source_types' => ['PARTNER_BUILD']]);

    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/publish")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'SOURCE_TYPE_NOT_PUBLISHABLE');
    expect($artifact->refresh()->status)->toBe(ArtifactStatus::Ready);
});

it('revokes a published artifact with a reason', function () {
    $artifact = inReview($this);
    approve($artifact)->assertOk();
    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/publish")->assertOk();

    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/revoke")->assertUnprocessable();
    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/revoke", ['reason' => 'Publisher withdrew consent'])
        ->assertOk()
        ->assertJsonPath('data.status', 'REVOKED');

    expect($this->catalogApp->publishedArtifact()->first())->toBeNull()
        ->and(AuditLog::where('action', 'app_artifact.status_changed')->latest('id')->first()->reason)->toBe('Publisher withdrew consent');
});

it('releases a quarantined file back to review or rejects it', function () {
    $scanner = tempnam(sys_get_temp_dir(), 'clamdscan');
    file_put_contents($scanner, "#!/bin/sh\necho \"\$3: Test-Signature FOUND\"\nexit 1\n");
    chmod($scanner, 0700);
    config(['storefront.inspection.clamdscan_path' => $scanner]);

    try {
        $released = inspected(uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->build()));
        $rejected = inspected(uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->version('2.0', '1')->build()));
    } finally {
        unlink($scanner);
    }
    expect($released->status)->toBe(ArtifactStatus::Quarantined);

    approve($released)->assertUnprocessable(); // a reason is required to release
    approve($released, ['reason' => 'False positive confirmed with vendor'])
        ->assertOk()
        ->assertJsonPath('data.decision', 'RELEASED')
        ->assertJsonPath('data.artifact.status', 'PROVENANCE_REVIEW');

    $this->postJson("/api/v1/admin/artifacts/{$rejected->public_id}/review", ['decision' => 'reject', 'reason' => 'Confirmed malware'])
        ->assertOk()
        ->assertJsonPath('data.artifact.status', 'REJECTED')
        ->assertJsonPath('data.artifact.status_reason', 'QUARANTINE_REJECTED');
});

it('can require a second person to approve', function () {
    config(['storefront.artifacts.independent_review' => true]);
    $artifact = inReview($this);

    approve($artifact)->assertForbidden();

    forgetGuards();
    asStaff(userWithRoles(RoleSlug::CatalogManager));
    approve($artifact)->assertOk()->assertJsonPath('data.artifact.status', 'READY');
});

it('re-inspects an artifact whose inspection failed', function () {
    config(['storefront.inspection.ratio_min_bytes' => 1024, 'storefront.inspection.max_compression_ratio' => 20]);
    $artifact = inspected(uploadIpa(
        $this->manager,
        $this->catalogApp,
        IpaBuilder::app()->withAppFile('padding.bin', str_repeat("\0", 256 * 1024))->build(),
    ));
    expect($artifact->status)->toBe(ArtifactStatus::InspectionFailed);

    config(['storefront.inspection.max_compression_ratio' => 100000]);
    $response = matchesContract($this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/inspect", ['reason' => 'Limit raised for large assets']), 'AdminArtifactReinspectResponse')
        ->assertStatus(202);

    expect($artifact->refresh()->status)->toBe(ArtifactStatus::ProvenanceReview)
        ->and($artifact->pipelineJobs()->count())->toBe(2)
        ->and(PipelineJob::where('public_id', $response->json('data.job.id'))->sole()->status)->toBe(PipelineJobStatus::Succeeded);
});

it('stores provenance documents privately and audits downloads', function () {
    $artifact = inReview($this);

    $this->post("/api/v1/admin/artifacts/{$artifact->public_id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('script.sh', '#!/bin/sh'),
    ], ['Accept' => 'application/json'])->assertUnprocessable();

    $document = matchesContract($this->post("/api/v1/admin/artifacts/{$artifact->public_id}/documents", [
        'file' => UploadedFile::fake()->createWithContent('license.txt', 'MIT License'),
        'description' => 'Upstream license',
    ], ['Accept' => 'application/json']), 'ProvenanceDocumentResponse')->assertCreated()->json('data');

    expect($document['sha256'])->toBe(hash('sha256', 'MIT License'));

    $download = $this->get($document['download_url'])->assertOk();
    expect($download->streamedContent())->toBe('MIT License')
        ->and($download->headers->get('Content-Disposition'))->toContain('license.txt');

    matchesContract($this->getJson("/api/v1/admin/artifacts/{$artifact->public_id}"), 'AdminArtifactDetailResponse')
        ->assertOk()
        ->assertJsonPath('data.documents.0.description', 'Upstream license')
        ->assertJsonPath('data.available_actions', ['approve', 'reject'])
        ->assertJsonPath('data.review_checklist.items', array_keys(CHECKLIST));

    expect(AuditLog::whereIn('action', ['artifact.document_added', 'artifact.document_downloaded'])->count())->toBe(2);
});

it('lists artifacts for the review queue and lets support read but not act', function () {
    $artifact = inReview($this);
    inspected(uploadIpa($this->manager, $this->catalogApp, 'not a zip'));

    matchesContract($this->getJson('/api/v1/admin/artifacts?status[]=PROVENANCE_REVIEW'), 'AdminArtifactListResponse')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $artifact->public_id)
        ->assertJsonPath('data.0.malware_scan', 'SCAN_UNAVAILABLE');

    $this->getJson('/api/v1/admin/artifacts?q=com.example')->assertOk()->assertJsonCount(1, 'data');

    forgetGuards();
    asStaff(userWithRoles(RoleSlug::Support));
    $this->getJson("/api/v1/admin/artifacts/{$artifact->public_id}")
        ->assertOk()
        ->assertJsonPath('data.available_actions', []);
    approve($artifact)->assertForbidden();
});

it('lists pipeline jobs and retries a failed one once', function () {
    $artifact = inReview($this);
    $job = $artifact->pipelineJobs()->sole();

    matchesContract($this->getJson('/api/v1/admin/jobs?status[]=SUCCEEDED'), 'AdminJobListResponse')
        ->assertOk()
        ->assertJsonPath('data.0.id', $job->public_id)
        ->assertJsonPath('data.0.subject.id', $artifact->public_id);
    matchesContract($this->getJson("/api/v1/admin/jobs/{$job->public_id}"), 'AdminJobDetailResponse')
        ->assertOk()
        ->assertJsonPath('data.attempts.0.result_code', 'INSPECTED');

    $this->postJson("/api/v1/admin/jobs/{$job->public_id}/retry", ['reason' => 'test'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ILLEGAL_STATE_TRANSITION');

    $job->forceFill(['status' => PipelineJobStatus::FailedPermanent, 'attempt' => 3])->save();
    $this->postJson("/api/v1/admin/jobs/{$job->public_id}/retry")->assertUnprocessable();
    matchesContract($this->postJson("/api/v1/admin/jobs/{$job->public_id}/retry", ['reason' => 'Disk was full']), 'AdminJobResponse')->assertStatus(202);

    // The artifact was already inspected, so the retried run finishes without redoing the work.
    expect($job->refresh()->status)->toBe(PipelineJobStatus::Succeeded)
        ->and($job->result_code)->toBe('ALREADY_INSPECTED')
        ->and($job->attempt)->toBe(4);
});
