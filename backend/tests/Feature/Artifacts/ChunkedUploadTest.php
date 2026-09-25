<?php

use App\Enums\RoleSlug;
use App\Models\AppArtifact;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->manager = userWithRoles(RoleSlug::CatalogManager);
    $this->catalogApp = CatalogApp::factory()->create();
});

function startUpload(User $manager, CatalogApp $app, string $contents, ?string $sha256 = null, ?int $size = null): array
{
    return asStaff($manager)->postJson('/api/v1/admin/uploads', [
        'app_id' => $app->public_id,
        'filename' => 'DemoApp.ipa',
        'size_bytes' => $size ?? strlen($contents),
        'sha256' => $sha256,
        'source_type' => 'OWN_BUILD',
        'declaration_version' => '2026-09-v1',
        'declaration_accepted' => true,
    ])->assertCreated()->json('data');
}

function putChunk(string $uploadId, int $number, string $contents, ?string $sha256 = null): TestResponse
{
    $headers = [
        'CONTENT_TYPE' => 'application/octet-stream',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_REFERER' => 'http://localhost',
    ];
    if ($sha256 !== null) {
        $headers['HTTP_X_CHUNK_SHA256'] = $sha256;
    }

    return test()->call('PUT', "/api/v1/admin/uploads/{$uploadId}/chunks/{$number}", [], [], [], $headers, $contents);
}

it('resumes a chunked upload and assembles an immutable artifact', function () {
    $contents = "PK\x03\x04synthetic ipa";
    $sha256 = hash('sha256', $contents);
    $upload = startUpload($this->manager, $this->catalogApp, $contents, $sha256);

    putChunk($upload['id'], 0, $contents, $sha256)
        ->assertOk()
        ->assertJsonPath('data.already_received', false);
    putChunk($upload['id'], 0, $contents, $sha256)
        ->assertOk()
        ->assertJsonPath('data.already_received', true);

    $this->getJson("/api/v1/admin/uploads/{$upload['id']}")
        ->assertOk()
        ->assertJsonPath('data.received_chunks', [0])
        ->assertJsonPath('data.missing_chunks', []);

    $artifact = $this->postJson("/api/v1/admin/uploads/{$upload['id']}/complete")
        ->assertCreated()
        ->assertJsonPath('data.status', 'UPLOADED')
        ->assertJsonPath('data.sha256', $sha256)
        ->json('data');

    $model = AppArtifact::where('public_id', $artifact['id'])->sole();
    Storage::disk('artifacts')->assertExists($model->storage_path);
    expect(Storage::disk('artifacts')->get($model->storage_path))->toBe($contents)
        ->and(UploadSession::where('public_id', $upload['id'])->sole()->status)->toBe('COMPLETED')
        ->and(AuditLog::orderBy('id')->pluck('action')->all())->toBe(['artifact.upload_started', 'artifact.uploaded']);
});

it('reports missing chunks without closing the resumable session', function () {
    $upload = startUpload($this->manager, $this->catalogApp, '', size: UploadSession::CHUNK_SIZE + 1);

    $this->postJson("/api/v1/admin/uploads/{$upload['id']}/complete")
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'UPLOAD_INCOMPLETE')
        ->assertJsonPath('error.details.missing_chunks', [0, 1]);

    expect(UploadSession::where('public_id', $upload['id'])->sole()->status)->toBe('OPEN');
});

it('rejects a corrupt chunk and a mismatched final checksum', function () {
    $contents = 'first payload';
    $upload = startUpload($this->manager, $this->catalogApp, $contents, str_repeat('a', 64));

    putChunk($upload['id'], 0, $contents, str_repeat('b', 64))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'UPLOAD_CORRUPT');

    putChunk($upload['id'], 0, $contents, hash('sha256', $contents))->assertOk();
    $this->postJson("/api/v1/admin/uploads/{$upload['id']}/complete")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'UPLOAD_CORRUPT');

    expect(UploadSession::where('public_id', $upload['id'])->sole()->status)->toBe('FAILED');
});

it('links duplicate content to the existing artifact', function () {
    $contents = 'same ipa payload';
    $first = startUpload($this->manager, $this->catalogApp, $contents);
    putChunk($first['id'], 0, $contents)->assertOk();
    $artifactId = $this->postJson("/api/v1/admin/uploads/{$first['id']}/complete")->json('data.id');

    $second = startUpload($this->manager, $this->catalogApp, $contents);
    putChunk($second['id'], 0, $contents)->assertOk();
    $this->postJson("/api/v1/admin/uploads/{$second['id']}/complete")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DUPLICATE_ARTIFACT')
        ->assertJsonPath('error.details.artifact_id', $artifactId);
});

it('requires a provenance declaration and limits upload changes to managers', function () {
    asStaff($this->manager)->postJson('/api/v1/admin/uploads', [
        'app_id' => $this->catalogApp->public_id,
        'filename' => 'DemoApp.ipa',
        'size_bytes' => 10,
        'source_type' => 'OWN_BUILD',
        'declaration_version' => '2026-09-v1',
        'declaration_accepted' => false,
    ])->assertUnprocessable();

    $upload = startUpload($this->manager, $this->catalogApp, 'payload');
    forgetGuards();
    $support = userWithRoles(RoleSlug::Support);
    asStaff($support)->getJson("/api/v1/admin/uploads/{$upload['id']}")->assertOk();
    $this->postJson('/api/v1/admin/uploads', [])->assertForbidden();
});
