<?php

use App\Enums\ArtifactStatus;
use App\Enums\PipelineJobStatus;
use App\Enums\RoleSlug;
use App\Models\AppArtifact;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\PipelineJob;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IpaBuilder;

/*
| tools/ipa-cleaner wired into the artifact pipeline: inspection reports injected modules,
| an operator asks for a cleaned copy, the copy goes through review and replaces the source.
*/

const PROMO_PATH = 'Payload/Demo.app/libobjcpatch.dylib';

beforeEach(function () {
    if (! Process::run(['python3', '--version'])->successful()) {
        $this->markTestSkipped('python3 is needed for tools/ipa-cleaner.');
    }
    Storage::fake('artifacts');
    config(['storefront.ipa_cleaner.enabled' => true]);
    approveTeamFor('com.example.demo');
    $this->manager = userWithRoles(RoleSlug::CatalogManager);
    $this->catalogApp = CatalogApp::factory()->create(['visibility' => 'PUBLISHED']);
});

/** An app with a promotional dylib appended to its load commands, as the supplied IPAs have. */
function injectedIpa(): string
{
    return IpaBuilder::app()
        ->executable(IpaBuilder::machO(loads: ['@executable_path/libobjcpatch.dylib']))
        ->withAppFile('libobjcpatch.dylib', IpaBuilder::machO().'https://t.me/promochannel RSPill')
        ->build();
}

function approveAndPublish(AppArtifact $artifact): void
{
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/review", [
        'decision' => 'approve',
        'checklist' => ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true],
        'acknowledge_scan_result' => true,
    ])->assertOk();
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/publish")->assertOk();
}

it('reports injected modules at inspection and replaces the upload with a cleaned copy', function () {
    $original = inspected(uploadIpa($this->manager, $this->catalogApp, injectedIpa()));
    expect($original->status)->toBe(ArtifactStatus::ProvenanceReview)
        ->and($original->inspection['cleaning']['recommended']['remove'])->toBe([PROMO_PATH])
        ->and($original->inspection['cleaning']['modules'][0]['telegram_links'])->toBe(['https://t.me/promochannel']);
    $this->getJson("/api/v1/admin/artifacts/{$original->public_id}")->assertJsonPath('data.available_actions', ['approve', 'reject', 'clean']);

    $this->postJson("/api/v1/admin/artifacts/{$original->public_id}/clean", ['recommended' => true, 'reason' => 'Убрать рекламу'])
        ->assertStatus(202)
        ->assertJsonPath('data.job.type', 'CleanArtifactJob');

    $copy = AppArtifact::query()->where('derived_from_artifact_id', $original->id)->sole();
    expect(PipelineJob::where('type', 'CleanArtifactJob')->sole()->result_code)->toBe('CLEANED')
        ->and($original->refresh()->status)->toBe(ArtifactStatus::ProvenanceFailed)
        ->and($original->status_reason)->toBe('REPLACED_BY_CLEANED_COPY')
        // The copy went through inspection like any upload, and has nothing left to clean.
        ->and($copy->status)->toBe(ArtifactStatus::ProvenanceReview)
        ->and($copy->version)->toBe($original->version)
        ->and($copy->inspection['cleaning']['recommended']['remove'])->toBe([])
        ->and(array_column($copy->cleaning_report['removed_modules'], 'path'))->toBe([PROMO_PATH])
        ->and($copy->cleaning_report['source_sha256'])->toBe($original->sha256)
        ->and(AuditLog::where('action', 'artifact.cleaned')->count())->toBe(1);

    $zip = new ZipArchive;
    $local = tempnam(sys_get_temp_dir(), 'clean');
    file_put_contents($local, Storage::disk('artifacts')->get($copy->storage_path));
    $zip->open($local);
    expect($zip->locateName(PROMO_PATH))->toBeFalse()
        ->and(hash('sha256', (string) file_get_contents($local)))->toBe($copy->sha256);
    $zip->close();
    unlink($local);

    $this->getJson("/api/v1/admin/artifacts/{$copy->public_id}")
        ->assertJsonPath('data.derived_from.id', $original->public_id)
        ->assertJsonPath('data.cleaning_report.removed_modules.0.path', PROMO_PATH);
    $this->getJson("/api/v1/admin/artifacts/{$original->public_id}")->assertJsonPath('data.cleaned_copies.0.id', $copy->public_id);

    approveAndPublish($copy);
    expect($copy->refresh()->status)->toBe(ArtifactStatus::Published);
});

it('cleans a published build into a copy of the same version that supersedes it on publication', function () {
    $original = inspected(uploadIpa($this->manager, $this->catalogApp, injectedIpa()));
    approveAndPublish($original);

    $this->postJson("/api/v1/admin/artifacts/{$original->public_id}/clean", ['remove' => [PROMO_PATH], 'reason' => 'Реклама в опубликованной версии'])->assertStatus(202);
    $copy = AppArtifact::query()->where('derived_from_artifact_id', $original->id)->sole();
    // The same version is allowed for its own source, and the source keeps serving installs meanwhile.
    expect($copy->status)->toBe(ArtifactStatus::ProvenanceReview)
        ->and($original->refresh()->status)->toBe(ArtifactStatus::Published);

    approveAndPublish($copy);
    expect($original->refresh()->status)->toBe(ArtifactStatus::Expired)
        ->and($original->status_reason)->toBe('SUPERSEDED');
});

it('reports a cleanup with nothing to do, and explains a refused one', function () {
    $plain = inspected(uploadIpa($this->manager, $this->catalogApp, IpaBuilder::app()->build()));
    expect($plain->inspection['cleaning']['modules'])->toBe([]);

    $this->postJson("/api/v1/admin/artifacts/{$plain->public_id}/clean", ['recommended' => true, 'reason' => 'Проверка'])->assertStatus(202);
    expect(PipelineJob::where('type', 'CleanArtifactJob')->sole()->result_code)->toBe('NOTHING_TO_CLEAN')
        ->and(AppArtifact::count())->toBe(1);

    $this->postJson("/api/v1/admin/artifacts/{$plain->public_id}/clean", ['remove' => ['Payload/Demo.app/missing.dylib'], 'reason' => 'Проверка'])->assertStatus(202);
    $refused = PipelineJob::where('type', 'CleanArtifactJob')->latest('id')->first();
    expect($refused->status)->toBe(PipelineJobStatus::FailedPermanent)
        ->and($refused->error_message_redacted)->toContain('Not an injected module')
        ->and($plain->refresh()->status)->toBe(ArtifactStatus::ProvenanceReview);

    $this->postJson("/api/v1/admin/artifacts/{$plain->public_id}/clean", ['reason' => 'Пусто'])->assertUnprocessable();
});

it('offers no cleaning without the tool, nor for builds in flight or withdrawn', function () {
    $artifact = inspected(uploadIpa($this->manager, $this->catalogApp, injectedIpa()));
    config(['storefront.ipa_cleaner.enabled' => false]);
    $this->getJson("/api/v1/admin/artifacts/{$artifact->public_id}")->assertJsonPath('data.available_actions', ['approve', 'reject']);
    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/clean", ['recommended' => true, 'reason' => 'x'])->assertStatus(503);

    config(['storefront.ipa_cleaner.enabled' => true]);
    approveAndPublish($artifact);
    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/revoke", ['reason' => 'Отозвано'])->assertOk();
    $this->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/clean", ['recommended' => true, 'reason' => 'x'])
        ->assertStatus(409)
        ->assertJsonPath('error.details.status', 'REVOKED');
});
