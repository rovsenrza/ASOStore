<?php

use App\Jobs\AssembleUploadJob;
use App\Models\AppArtifact;
use App\Models\PipelineJob;
use App\Models\UploadSession;
use App\Services\Artifacts\ChunkedUploadService;
use App\Services\Pipeline\PipelineJobService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IpaBuilder;

// A customer import too big for the request is assembled by a queued job, and the app is told at once.

beforeEach(function () {
    Storage::fake('artifacts');
    $this->customer = subscribedCustomer();
    Sanctum::actingAs($this->customer);
    config(['storefront.uploads.background_assembly_bytes' => 1]);

    $this->startBigImport = function (string $package = 'com.vendor.big') {
        $bytes = IpaBuilder::app($package)->build();
        $start = $this->postJson('/api/v1/imports', ['filename' => 'Big.ipa', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'declaration_accepted' => true])
            ->assertCreated()->json('data');
        $this->call('PUT', "/api/v1/imports/{$start['id']}/chunks/0", [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json',
        ], $bytes)->assertOk();

        return $start['id'];
    };
    $this->runJob = function (string $uploadId) {
        $job = PipelineJob::where('idempotency_key', 'assemble:'.$uploadId)->sole();
        (new AssembleUploadJob($job->id))->handle(app(PipelineJobService::class));

        return $job->refresh();
    };
});

it('answers at once for a big file and assembles it in the background', function () {
    Queue::fake();
    $id = ($this->startBigImport)();

    $this->postJson("/api/v1/imports/{$id}/complete")->assertStatus(202)
        ->assertJsonPath('data.status', 'ASSEMBLING')
        ->assertJsonPath('data.size_bytes', UploadSession::where('public_id', $id)->sole()->expected_size);
    expect(UploadSession::where('public_id', $id)->sole()->status)->toBe('ASSEMBLING');
    Queue::assertPushed(AssembleUploadJob::class);

    // The app calls again when it did not hear back: the same answer, not "session already closed".
    $this->postJson("/api/v1/imports/{$id}/complete")->assertStatus(202)->assertJsonPath('data.status', 'ASSEMBLING');
    // Meanwhile the import is shown as uploading.
    $this->getJson('/api/v1/imports')->assertOk()->assertJsonPath('data.0.status', 'UPLOADING');

    ($this->runJob)($id);

    $session = UploadSession::where('public_id', $id)->sole();
    expect($session->status)->toBe('COMPLETED')
        ->and(AppArtifact::where('id', $session->artifact_id)->exists())->toBeTrue();
    // Once stored, asking again gives the artifact, as for a small file.
    $this->postJson("/api/v1/imports/{$id}/complete")->assertCreated()->assertJsonPath('data.sha256', $session->artifact->sha256);
});

it('finishes inside the request when the queue runs at once', function () {
    $id = ($this->startBigImport)('com.vendor.inline');

    $this->postJson("/api/v1/imports/{$id}/complete")->assertCreated()->assertJsonPath('data.status', 'PROVENANCE_REVIEW');
    expect(UploadSession::where('public_id', $id)->sole()->status)->toBe('COMPLETED');
});

it('ends the upload with a sentence when a chunk was lost, instead of staying stuck', function () {
    Queue::fake();
    $id = ($this->startBigImport)('com.vendor.lostbg');
    $this->postJson("/api/v1/imports/{$id}/complete")->assertStatus(202);
    Storage::disk('artifacts')->delete(UploadSession::where('public_id', $id)->sole()->chunks()->sole()->storage_path);

    $job = ($this->runJob)($id);

    $session = UploadSession::where('public_id', $id)->sole();
    expect($session->status)->toBe('FAILED')
        ->and($session->failure_reason)->toBe(ChunkedUploadService::BACKGROUND_FAILURE)
        ->and($job->result_code)->toBe('UPLOAD_INCOMPLETE');
    $this->getJson('/api/v1/imports')->assertOk()
        ->assertJsonPath('data.0.status', 'UPLOAD_FAILED')->assertJsonPath('data.0.failure_reason', ChunkedUploadService::BACKGROUND_FAILURE);
});

it('ends a stuck assembly as failed when the job gives up', function () {
    Queue::fake();
    $id = ($this->startBigImport)('com.vendor.giveup');
    $this->postJson("/api/v1/imports/{$id}/complete")->assertStatus(202);
    $job = PipelineJob::where('idempotency_key', 'assemble:'.$id)->sole();

    (fn () => $this->failedPermanently($job, new RuntimeException('storage down')))->call(new AssembleUploadJob($job->id));

    $session = UploadSession::where('public_id', $id)->sole();
    expect($session->status)->toBe('FAILED')->and($session->failure_reason)->toBe(ChunkedUploadService::BACKGROUND_FAILURE);
});

it('queues the assembly again for a stuck upload when the app asks once more', function () {
    $id = ($this->startBigImport)('com.vendor.stuck');
    // A session left ASSEMBLING by a request that died (the old behaviour) has no job at all.
    UploadSession::where('public_id', $id)->update(['status' => 'ASSEMBLING']);
    PipelineJob::query()->delete();
    Queue::fake();

    $this->postJson("/api/v1/imports/{$id}/complete")->assertStatus(202);

    Queue::assertPushed(AssembleUploadJob::class);
    expect(PipelineJob::where('idempotency_key', 'assemble:'.$id)->count())->toBe(1);
});

it('keeps a small file inside the request', function () {
    config(['storefront.uploads.background_assembly_bytes' => 100 * 1024 * 1024]);
    Queue::fake();
    $id = ($this->startBigImport)('com.vendor.small');

    $this->postJson("/api/v1/imports/{$id}/complete")->assertCreated();
    Queue::assertNotPushed(AssembleUploadJob::class);
});
