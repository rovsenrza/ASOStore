<?php

use App\Enums\ArtifactStatus;
use App\Enums\PipelineJobStatus;
use App\Enums\RoleSlug;
use App\Enums\SourceType;
use App\Models\AppArtifact;
use App\Models\AppCategory;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\PipelineJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IpaBuilder;

/*
| Admin «Быстрая публикация»: an uploaded IPA goes through inspection, cleaning, listing
| match-or-create, approval and publication without a person in between, and stops (with a
| reason) wherever a person is needed.
*/

beforeEach(function () {
    if (! Process::run(['python3', '--version'])->successful()) {
        $this->markTestSkipped('python3 is needed for tools/ipa-cleaner.');
    }
    Storage::fake('artifacts');
    Storage::fake('public');
    config([
        'storefront.ipa_cleaner.enabled' => true,
        'storefront.quick_publish.lookup_enabled' => false,
    ]);
    // A scanner that reports every file clean; without one the scan is "unavailable" and nothing publishes by itself.
    $this->scanner = tempnam(sys_get_temp_dir(), 'clamd');
    file_put_contents($this->scanner, "#!/bin/sh\nexit 0\n");
    chmod($this->scanner, 0755);
    config(['storefront.inspection.clamdscan_path' => $this->scanner]);

    connectFakeAppleTeam();
    $this->manager = userWithRoles(RoleSlug::CatalogManager);
});

afterEach(fn () => @unlink($this->scanner));

function qpIpa(string $bundle = 'com.example.fresh', string $version = '2.0', string $build = '20', string $name = 'Fresh App'): IpaBuilder
{
    return (new IpaBuilder($bundle, $name))->version($version, $build);
}

/** An app with a promotional dylib and an unknown hook dylib appended to its load commands. */
function qpInjected(string $bundle = 'com.example.fresh'): string
{
    return qpIpa($bundle)
        ->executable(IpaBuilder::machO(loads: ['@executable_path/libobjcpatch.dylib', '@executable_path/hook.dylib']))
        ->withAppFile('libobjcpatch.dylib', IpaBuilder::machO().'https://t.me/promochannel RSPill')
        ->withAppFile('hook.dylib', IpaBuilder::machO().'MSHookMessageEx')
        ->build();
}

/** Uploads through the quick-publish API; the sync queue runs the whole pipeline inline. */
function qpUpload(object $test, string $bytes, array $options = [], string $filename = 'Fresh.ipa'): array
{
    $upload = asStaff($test->manager)->postJson('/api/v1/admin/quick-publish/uploads', [
        'filename' => $filename, 'size_bytes' => strlen($bytes), 'declaration_accepted' => true,
    ])->assertCreated()->json('data');

    test()->call('PUT', "/api/v1/admin/uploads/{$upload['id']}/chunks/0", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_REFERER' => 'http://localhost',
    ], $bytes)->assertOk();

    return test()->postJson("/api/v1/admin/quick-publish/uploads/{$upload['id']}/complete", ['options' => $options])
        ->assertCreated()->json('data');
}

function qpJob(string $publicId): PipelineJob
{
    return PipelineJob::query()->where('public_id', $publicId)->sole();
}

/** A listing that already has a published build, made by hand the way an operator would. */
function qpExistingListing(object $test, string $bundle = 'com.example.fresh', string $version = '1.0', string $source = 'CUSTOMER_PROVIDED'): array
{
    $app = CatalogApp::factory()->create([
        'bundle_identifier' => 'com.ruappstore.tgexisting', 'source_type' => $source, 'visibility' => 'PUBLISHED', 'name' => 'Existing',
    ]);
    // The listing carries a signing ID of ours, but it was made by hand: its team approval is too.
    approveTeamFor('com.ruappstore.tgexisting');
    $data = uploadIpa($test->manager, $app, qpIpa($bundle, $version, '10')->build(), sourceType: $source);
    $artifact = inspected($data);
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/review", [
        'decision' => 'approve', 'checklist' => ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true],
        'acknowledge_scan_result' => true,
    ])->assertOk();
    test()->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/publish")->assertOk();
    expect($artifact->refresh()->status)->toBe(ArtifactStatus::Published);

    return [$app, $artifact];
}

it('turns an uploaded IPA into a published listing without a person in between', function () {
    $data = qpUpload($this, qpIpa()->build());

    expect($data['stage'])->toBe('PUBLISHED')
        ->and($data['result']['action'])->toBe('created')
        ->and($data['result']['name'])->toBe('Fresh App');

    $app = CatalogApp::query()->where('public_id', $data['result']['app_id'])->sole();
    $artifact = AppArtifact::query()->where('public_id', $data['result']['artifact_id'])->sole();
    expect($app->visibility->value)->toBe('PUBLISHED')
        ->and($app->slug)->toStartWith('tg-')
        ->and($app->bundle_identifier)->toStartWith('com.ruappstore.tg')
        ->and($app->source_type)->toBe(SourceType::CustomerProvided)
        ->and($artifact->status)->toBe(ArtifactStatus::Published)
        ->and($artifact->app_id)->toBe($app->id)
        // The staging listing the file waited in is gone, and nothing else was created.
        ->and(CatalogApp::query()->where('slug', 'like', 'quick-%')->count())->toBe(0)
        ->and(CatalogApp::query()->count())->toBe(1)
        ->and(qpJob($data['id'])->status)->toBe(PipelineJobStatus::Succeeded)
        ->and(qpJob($data['id'])->result_code)->toBe('PUBLISHED')
        ->and(AuditLog::query()->where('action', 'quick_publish.published')->count())->toBe(1);
});

it('removes promotions and unknown hook libraries before publishing, and keeps the source as evidence', function () {
    $data = qpUpload($this, qpInjected());

    expect($data['stage'])->toBe('PUBLISHED');
    $published = AppArtifact::query()->where('public_id', $data['result']['artifact_id'])->sole();
    $source = AppArtifact::query()->where('id', $published->derived_from_artifact_id)->sole();

    expect($data['result']['removed_libraries'])->toEqualCanonicalizing(['libobjcpatch.dylib', 'hook.dylib'])
        ->and($published->status)->toBe(ArtifactStatus::Published)
        ->and($source->status)->toBe(ArtifactStatus::ProvenanceFailed)
        ->and($source->status_reason)->toBe('REPLACED_BY_CLEANED_COPY')
        // Both belong to the new listing, so the history stays in one place.
        ->and($source->app_id)->toBe($published->app_id);
});

it('leaves unknown libraries alone when asked to, but still removes the reviewed promotions', function () {
    $data = qpUpload($this, qpInjected(), ['remove_unknown_libraries' => false]);

    expect($data['stage'])->toBe('PUBLISHED')
        ->and($data['result']['removed_libraries'])->toBe(['libobjcpatch.dylib']);
});

it('replaces the live build of the listing that already carries the bundle ID', function () {
    [$app, $old] = qpExistingListing($this);

    $data = qpUpload($this, qpIpa(version: '1.1', build: '11')->build());

    expect($data['stage'])->toBe('PUBLISHED')
        ->and($data['result']['action'])->toBe('updated')
        ->and($data['result']['app_id'])->toBe($app->public_id)
        ->and($data['result']['superseded'])->toBe([$old->public_id])
        ->and($old->refresh()->status)->toBe(ArtifactStatus::Expired)
        ->and($old->status_reason)->toBe('SUPERSEDED')
        ->and($app->artifacts()->where('status', 'PUBLISHED')->sole()->version)->toBe('1.1')
        // No extra listing: the staging one is gone and the existing one kept its name.
        ->and(CatalogApp::query()->count())->toBe(1)
        ->and($app->refresh()->name)->toBe('Existing');
});

it('holds a downgrade until it is allowed, then publishes it', function () {
    [$app, $live] = qpExistingListing($this, version: '2.0');

    $held = qpUpload($this, qpIpa(version: '1.5', build: '15')->build());
    expect($held['stage'])->toBe('HELD')
        ->and($held['hold_code'])->toBe('DOWNGRADE')
        ->and($held['message'])->toContain('1.5')
        ->and($live->refresh()->status)->toBe(ArtifactStatus::Published);

    $resumed = $this->postJson("/api/v1/admin/quick-publish/{$held['id']}/resume", ['options' => ['allow_downgrade' => true]])
        ->assertStatus(202)->json('data');

    expect($resumed['id'])->not->toBe($held['id'])
        ->and($resumed['stage'])->toBe('PUBLISHED')
        ->and($live->refresh()->status)->toBe(ArtifactStatus::Expired)
        ->and($app->artifacts()->where('status', 'PUBLISHED')->sole()->version)->toBe('1.5');
});

it('does not update a listing that came from another source unless allowed', function () {
    [$app, $live] = qpExistingListing($this, source: 'ALTERNATIVE_MARKETPLACE_PACKAGE');

    $held = qpUpload($this, qpIpa(version: '1.1', build: '11')->build());
    expect($held['stage'])->toBe('HELD')->and($held['hold_code'])->toBe('OTHER_SOURCE');

    $resumed = $this->postJson("/api/v1/admin/quick-publish/{$held['id']}/resume", ['options' => ['allow_other_sources' => true]])
        ->assertStatus(202)->json('data');

    expect($resumed['stage'])->toBe('PUBLISHED')
        ->and($app->artifacts()->where('status', 'PUBLISHED')->sole()->source_type)->toBe(SourceType::AlternativeMarketplacePackage);
});

it('stops and waits for a second person when four-eyes review is on', function () {
    config(['storefront.artifacts.independent_review' => true]);

    $data = qpUpload($this, qpIpa()->build());

    expect($data['stage'])->toBe('HELD')
        ->and($data['hold_code'])->toBe('REVIEW')
        ->and($data['message'])->toContain('другой человек');
    $artifact = AppArtifact::query()->where('public_id', $data['artifact_id'])->sole();
    expect($artifact->status)->toBe(ArtifactStatus::ProvenanceReview);
});

it('never publishes a file the antivirus did not confirm clean', function () {
    config(['storefront.inspection.clamdscan_path' => null]);

    $data = qpUpload($this, qpIpa()->build());

    expect($data['stage'])->toBe('HELD')
        ->and($data['message'])->toContain('Антивирусная проверка')
        ->and(AppArtifact::query()->where('public_id', $data['artifact_id'])->sole()->status)->toBe(ArtifactStatus::ProvenanceReview);
});

it('rejects an App Store copy whose code is still encrypted', function () {
    $encrypted = qpIpa()->executable(IpaBuilder::machO(cryptId: 1))->build();

    $data = qpUpload($this, $encrypted);

    expect($data['stage'])->toBe('REJECTED')
        ->and(AppArtifact::query()->where('public_id', $data['artifact_id'])->sole()->status)->not->toBe(ArtifactStatus::Published);
});

it('does not let a customer\'s private import of the same file block the upload', function () {
    $bytes = qpIpa()->build();
    $import = CatalogApp::factory()->create(['source_type' => 'USER_IMPORT', 'visibility' => 'HIDDEN', 'imported_by_user_id' => userWithRoles(RoleSlug::Customer)->id]);
    AppArtifact::factory()->create([
        'app_id' => $import->id, 'sha256' => hash('sha256', $bytes), 'source_type' => 'USER_IMPORT', 'status' => ArtifactStatus::Published,
        'bundle_identifier' => 'com.example.fresh', 'version' => '2.0', 'build_number' => '20',
    ]);

    $data = qpUpload($this, $bytes);

    // The import stays its owner's: the catalog gets a listing of its own.
    expect($data['stage'])->toBe('PUBLISHED')
        ->and($data['result']['action'])->toBe('created')
        ->and($data['result']['app_id'])->not->toBe($import->public_id);
});

it('takes name, developer and category from the store lookup for a new listing', function () {
    config(['storefront.quick_publish.lookup_enabled' => true]);
    AppCategory::firstOrCreate(['slug' => 'music'], ['title' => 'Музыка', 'kind' => 'APPS', 'sort_order' => 6]);
    Http::fake([
        'itunes.apple.com/*' => Http::response(['results' => [[
            'trackName' => 'Fresh Store Name', 'artistName' => 'Fresh Studio', 'primaryGenreName' => 'Music', 'trackId' => 1234567, 'artworkUrl512' => 'https://is1-ssl.mzstatic.com/icon.png',
        ]]]),
        'is1-ssl.mzstatic.com/*' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 200, ['Content-Type' => 'image/png']),
    ]);

    $data = qpUpload($this, qpIpa()->build());

    $app = CatalogApp::query()->where('public_id', $data['result']['app_id'])->sole();
    expect($app->name)->toBe('Fresh Store Name')
        ->and($app->publisher->name)->toBe('Fresh Studio')
        ->and($app->category->slug)->toBe('music')
        ->and($app->app_store_id)->toBe('1234567')
        ->and($app->icon_path)->not->toBeNull();
});

it('lists recent runs and shows one, but only to staff who may', function () {
    $data = qpUpload($this, qpIpa()->build());

    $this->getJson('/api/v1/admin/quick-publish')->assertOk()->assertJsonPath('data.0.id', $data['id']);
    $this->getJson("/api/v1/admin/quick-publish/{$data['id']}")->assertOk()->assertJsonPath('data.stage', 'PUBLISHED')->assertJsonPath('data.finished', true);

    $support = userWithRoles(RoleSlug::Support);
    forgetGuards();
    asStaff($support)->postJson('/api/v1/admin/quick-publish/uploads', ['filename' => 'A.ipa', 'size_bytes' => 10, 'declaration_accepted' => true])->assertForbidden();
});

it('needs the operator to accept the declaration and send an .ipa', function () {
    asStaff($this->manager);
    $this->postJson('/api/v1/admin/quick-publish/uploads', ['filename' => 'A.ipa', 'size_bytes' => 10])->assertUnprocessable();
    $this->postJson('/api/v1/admin/quick-publish/uploads', ['filename' => 'A.zip', 'size_bytes' => 10, 'declaration_accepted' => true])->assertUnprocessable();
});

it('does not let one operator complete another operator\'s upload', function () {
    $upload = asStaff($this->manager)->postJson('/api/v1/admin/quick-publish/uploads', [
        'filename' => 'A.ipa', 'size_bytes' => 10, 'declaration_accepted' => true,
    ])->assertCreated()->json('data');

    forgetGuards();
    asStaff(userWithRoles(RoleSlug::CatalogManager))->postJson("/api/v1/admin/quick-publish/uploads/{$upload['id']}/complete")->assertNotFound();
});
