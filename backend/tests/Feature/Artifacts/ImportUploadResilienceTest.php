<?php

use App\Models\CatalogApp;
use App\Models\UploadSession;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use League\Flysystem\UnableToWriteFile;
use Tests\Support\IpaBuilder;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->customer = subscribedCustomer();
    Sanctum::actingAs($this->customer);
});

function putImportChunk(string $uploadId, int $number, string $bytes)
{
    return test()->call('PUT', "/api/v1/imports/{$uploadId}/chunks/{$number}", [], [], [], [
        'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json',
    ], $bytes);
}

/** The artifacts disk, failing the next $failures writes of an original as a broken multipart upload would. */
function flakyOriginals(int $failures): FilesystemAdapter
{
    $disk = Storage::disk('artifacts');
    $flaky = new class($disk->getDriver(), $disk->getAdapter(), $disk->getConfig()) extends FilesystemAdapter
    {
        public int $failures = 0;

        public function put($path, $contents, $options = [])
        {
            if (str_starts_with($path, 'originals/') && $this->failures > 0) {
                $this->failures--;
                throw UnableToWriteFile::atLocation($path, 'An exception occurred while uploading parts to a multipart upload.');
            }

            return parent::put($path, $contents, $options);
        }
    };
    $flaky->failures = $failures;
    Storage::set('artifacts', $flaky);

    return $flaky;
}

it('continues the open upload when the same file is picked again', function () {
    config(['storefront.imports.daily_limit' => 1]);
    $bytes = str_repeat('a', UploadSession::CHUNK_SIZE + 10);
    $start = ['filename' => 'Big.ipa', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'declaration_accepted' => true];

    $first = $this->postJson('/api/v1/imports', $start)->assertCreated()->json('data');
    putImportChunk($first['id'], 0, substr($bytes, 0, UploadSession::CHUNK_SIZE))->assertOk();

    // The app was closed mid-upload; the customer picks the same file again. Even with the
    // day's cap used up, it continues where it stopped rather than starting a second import.
    $again = $this->postJson('/api/v1/imports', $start)->assertSuccessful()->json('data');
    expect($again['id'])->toBe($first['id'])
        ->and($again['import_id'])->toBe($first['import_id'])
        ->and($again['received_chunks'])->toBe([0])
        ->and($again['missing_chunks'])->toBe([1])
        ->and(CatalogApp::where('imported_by_user_id', $this->customer->id)->count())->toBe(1);
});

it('starts over once the customer deleted the unfinished import, or the file differs', function () {
    $bytes = str_repeat('b', 64);
    $start = ['filename' => 'A.ipa', 'size_bytes' => 64, 'sha256' => hash('sha256', $bytes), 'declaration_accepted' => true];
    $first = $this->postJson('/api/v1/imports', $start)->assertCreated()->json('data');

    $other = $this->postJson('/api/v1/imports', ['sha256' => hash('sha256', 'other')] + $start)->assertCreated()->json('data');
    expect($other['id'])->not->toBe($first['id']);

    $this->deleteJson("/api/v1/imports/{$first['import_id']}")->assertOk();
    $fresh = $this->postJson('/api/v1/imports', $start)->assertCreated()->json('data');
    expect($fresh['id'])->not->toBe($first['id']);
});

it('rides out a failed write to object storage', function () {
    $bytes = IpaBuilder::app('com.vendor.flaky')->build();
    $start = $this->postJson('/api/v1/imports', ['filename' => 'Flaky.ipa', 'size_bytes' => strlen($bytes), 'declaration_accepted' => true])
        ->assertCreated()->json('data');
    putImportChunk($start['id'], 0, $bytes)->assertOk();
    flakyOriginals(1);

    $this->postJson("/api/v1/imports/{$start['id']}/complete")->assertCreated()->assertJsonPath('data.status', 'PROVENANCE_REVIEW');
});

it('keeps the upload open when storage keeps failing, so completing it again works', function () {
    $bytes = IpaBuilder::app('com.vendor.down')->build();
    $start = $this->postJson('/api/v1/imports', ['filename' => 'Down.ipa', 'size_bytes' => strlen($bytes), 'declaration_accepted' => true])
        ->assertCreated()->json('data');
    putImportChunk($start['id'], 0, $bytes)->assertOk();
    $flaky = flakyOriginals(3);

    $this->postJson("/api/v1/imports/{$start['id']}/complete")
        ->assertStatus(503)->assertJsonPath('error.code', 'SERVICE_UNAVAILABLE');
    expect(UploadSession::where('public_id', $start['id'])->sole()->status)->toBe('OPEN')
        ->and($flaky->failures)->toBe(0);

    $this->postJson("/api/v1/imports/{$start['id']}/complete")->assertCreated()->assertJsonPath('data.status', 'PROVENANCE_REVIEW');
});
