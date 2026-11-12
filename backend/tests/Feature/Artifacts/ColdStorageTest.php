<?php

use App\Models\AppArtifact;
use App\Services\Artifacts\ColdStorage;
use App\Services\Artifacts\LocalArtifactFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['storefront.cold_storage.enabled' => true, 'storefront.cold_storage.days' => 14]);
    Storage::fake('artifacts');
    Storage::fake('artifacts_cold');
    $this->original = function (string $bytes, array $attributes = []): AppArtifact {
        $sha = hash('sha256', $bytes);
        $path = 'originals/'.substr($sha, 0, 2)."/{$sha}.ipa";
        Storage::disk('artifacts')->put($path, $bytes);
        Storage::disk('artifacts_cold')->put($path, $bytes);

        return AppArtifact::factory()->create(['sha256' => $sha, 'size_bytes' => strlen($bytes), 'storage_disk' => 'artifacts', 'storage_path' => $path] + $attributes);
    };
});

it('brings a cold original back from the bucket, verified, for every reader', function () {
    $artifact = ($this->original)('cold-ipa-bytes');
    unlink(Storage::disk('artifacts')->path($artifact->storage_path));

    $file = LocalArtifactFile::open(Storage::disk('artifacts'), $artifact->storage_path);

    expect(file_get_contents($file->path))->toBe('cold-ipa-bytes')
        ->and(app(ColdStorage::class)->isLocal($artifact))->toBeTrue();
});

it('refuses a bucket copy that does not match the artifact', function () {
    $artifact = ($this->original)('good-bytes');
    unlink(Storage::disk('artifacts')->path($artifact->storage_path));
    Storage::disk('artifacts_cold')->put($artifact->storage_path, 'bad-bytes!');

    expect(app(ColdStorage::class)->ensureLocal($artifact))->toBeFalse()
        ->and(app(ColdStorage::class)->isLocal($artifact))->toBeFalse();
});

it('moves only idle originals that have a bucket copy', function () {
    $idle = ($this->original)('idle-app');
    $idle->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
    $fresh = ($this->original)('fresh-app');
    $noCopy = ($this->original)('no-copy-app');
    $noCopy->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
    Storage::disk('artifacts_cold')->delete($noCopy->storage_path);

    $this->artisan('artifacts:cold', ['--dry-run' => true])->assertSuccessful();
    expect(app(ColdStorage::class)->isLocal($idle))->toBeTrue();

    $this->artisan('artifacts:cold')->assertSuccessful();
    $this->artisan('artifacts:cold', ['--status' => true])->expectsOutputToContain('cold: 1 files')->assertSuccessful();
    $cold = app(ColdStorage::class);
    expect($cold->isLocal($idle))->toBeFalse()
        ->and($cold->isLocal($fresh))->toBeTrue()
        ->and($cold->isLocal($noCopy))->toBeTrue()
        ->and($cold->ensureLocal($idle))->toBeTrue()
        ->and(file_get_contents($cold->localPath($idle)))->toBe('idle-app');
});
