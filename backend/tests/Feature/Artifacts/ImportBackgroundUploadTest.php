<?php

use App\Models\UploadSession;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\IpaBuilder;
use Tests\Support\OpenApiContract;

beforeEach(function () {
    Storage::fake('artifacts');
    $this->customer = subscribedCustomer();
    Sanctum::actingAs($this->customer);
});

function putTokenChunk(string $uploadId, int $number, string $bytes, ?string $token)
{
    $headers = ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'];
    if ($token !== null) {
        $headers['HTTP_X_UPLOAD_TOKEN'] = $token;
    }

    return test()->call('PUT', "/api/v1/import-uploads/{$uploadId}/chunks/{$number}", [], [], [], $headers, $bytes);
}

function startTokenImport(string $bytes): array
{
    return test()->postJson('/api/v1/imports', [
        'filename' => 'Background.ipa', 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'declaration_accepted' => true,
    ])->assertCreated()->json('data');
}

it('accepts chunks with the upload token alone and completes the upload after the last one', function () {
    $bytes = IpaBuilder::app()->build();
    $upload = startTokenImport($bytes);
    expect($upload['upload_token'])->toBeString()->toMatch('/^\d+\.[0-9a-f]{64}$/')
        ->and($upload['upload_token_expires_at'])->toBeString();

    // The background session sends no access token: the app may be closed by now.
    app('auth')->forgetGuards();
    $size = $upload['chunk_size'];
    for ($number = $upload['chunk_count'] - 1; $number >= 0; $number--) {
        putTokenChunk($upload['id'], $number, substr($bytes, $number * $size, $size), $upload['upload_token'])->assertOk();
    }

    // Nobody called complete: the server did it on the last chunk (the queue runs inline in tests).
    $session = UploadSession::where('public_id', $upload['id'])->firstOrFail();
    expect($session->status)->toBe('COMPLETED')->and($session->artifact_id)->not->toBeNull();

    // The app's own complete call afterwards still answers normally.
    Sanctum::actingAs($this->customer);
    $this->postJson("/api/v1/imports/{$upload['id']}/complete")->assertSuccessful()
        ->assertJsonPath('data.import_id', $upload['import_id']);
});

it('rejects a missing or wrong upload token', function () {
    $bytes = IpaBuilder::app()->build();
    $upload = startTokenImport($bytes);
    $chunk = substr($bytes, 0, $upload['chunk_size']);

    putTokenChunk($upload['id'], 0, $chunk, null)->assertNotFound();
    putTokenChunk($upload['id'], 0, $chunk, str_repeat('0', 64))->assertNotFound();
    expect(UploadSession::where('public_id', $upload['id'])->firstOrFail()->chunks()->count())->toBe(0);
});

it('reports how far an unfinished upload got and whether chunks are still arriving', function () {
    $bytes = str_repeat('b', UploadSession::chunkSize() * 3);
    $upload = startTokenImport($bytes);
    putTokenChunk($upload['id'], 0, substr($bytes, 0, UploadSession::chunkSize()), $upload['upload_token'])->assertOk();

    $row = collect($this->getJson('/api/v1/imports')->assertOk()->json('data'))->firstWhere('id', $upload['import_id']);
    expect($row['status'])->toBe('UPLOADING')
        ->and($row['upload_progress'])->toEqual(0.333)
        ->and($row['upload_active'])->toBeTrue();

    $this->travel(5)->minutes();
    $row = collect($this->getJson('/api/v1/imports')->assertOk()->json('data'))->firstWhere('id', $upload['import_id']);
    expect($row['upload_active'])->toBeFalse();
});

it('expires the upload token and lets the owner refresh it', function () {
    config(['storefront.uploads.token_ttl_minutes' => 60]);
    $bytes = str_repeat('b', UploadSession::chunkSize() * 2);
    $upload = startTokenImport($bytes);
    $chunk = substr($bytes, 0, UploadSession::chunkSize());
    expect($upload['upload_token_expires_at'])->toBe(now()->addHour()->startOfSecond()->toIso8601ZuluString());

    $this->travel(61)->minutes();
    putTokenChunk($upload['id'], 0, $chunk, $upload['upload_token'])
        ->assertUnauthorized()
        ->assertJsonPath('error.details.reason', 'UPLOAD_TOKEN_EXPIRED');

    $fresh = $this->postJson("/api/v1/imports/{$upload['id']}/token")->assertOk()
        ->assertJsonPath('data.id', $upload['id'])
        ->assertJsonPath('data.upload_token_expires_at', now()->addHour()->startOfSecond()->toIso8601ZuluString())
        ->json('data');
    expect(OpenApiContract::errors($this->postJson("/api/v1/imports/{$upload['id']}/token")->getContent(), 'ImportUploadResponse'))->toBe([]);
    putTokenChunk($upload['id'], 0, $chunk, $fresh['upload_token'])->assertOk();

    // A token from before expiries existed still works while the session is open.
    $legacy = hash_hmac('sha256', 'import-upload|'.$upload['id'], (string) config('app.key'));
    putTokenChunk($upload['id'], 1, substr($bytes, UploadSession::chunkSize()), $legacy)->assertOk();
});

it('refreshes the upload token only for the owner of an open upload', function () {
    $upload = startTokenImport(str_repeat('b', 10));

    Sanctum::actingAs(subscribedCustomer());
    $this->postJson("/api/v1/imports/{$upload['id']}/token")->assertNotFound();

    Sanctum::actingAs($this->customer);
    UploadSession::where('public_id', $upload['id'])->update(['status' => 'FAILED']);
    $this->postJson("/api/v1/imports/{$upload['id']}/token")->assertConflict();
});
