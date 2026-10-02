<?php

use App\Services\Artifacts\ArtifactFileCache;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->cacheRoot = sys_get_temp_dir().'/artifact-cache-test-'.bin2hex(random_bytes(8));
    config(['storefront.artifacts.file_cache_path' => $this->cacheRoot]);
    $this->etag = 'object-version-1';
    $this->requests = [];
    $test = $this;
    $mock = new MockHandler;
    foreach (range(1, 12) as $ignored) {
        $mock->append(function ($command) use ($test) {
            $test->requests[] = $command->getName();
            if ($command->getName() !== 'HeadObject') {
                throw new RuntimeException('The cached file must not be downloaded again.');
            }

            return new Result(['ContentLength' => 7, 'ETag' => '"'.$test->etag.'"']);
        });
    }
    $this->disk = Storage::build([
        'driver' => 's3', 'key' => 'test', 'secret' => 'test', 'region' => 'default', 'bucket' => 'test',
        'endpoint' => 'https://storage.test', 'use_path_style_endpoint' => true, 'throw' => true, 'handler' => $mock,
    ]);
});

afterEach(function () {
    if (is_dir($this->cacheRoot)) {
        app('files')->deleteDirectory($this->cacheRoot);
    }
});

function cacheTestUpload(object $test, string $bytes = 'payload'): string
{
    $hash = hash('sha256', $bytes);
    $source = fopen('php://temp', 'w+b');
    fwrite($source, $bytes);
    rewind($source);
    app(ArtifactFileCache::class)->store($test->disk, 'signed/test.ipa', $hash, strlen($bytes), $source);
    fclose($source);

    return $hash;
}

it('reuses uploaded bytes for verification and delivery without another object download', function () {
    $hash = cacheTestUpload($this);
    $cache = app(ArtifactFileCache::class);
    $path = $cache->get($this->disk, 'signed/test.ipa', $hash, 7);
    expect($path)->not->toBeNull()
        ->and(file_get_contents($path))->toBe('payload')
        ->and($cache->get($this->disk, 'signed/test.ipa', $hash, 7))->toBe($path)
        ->and(array_unique($this->requests))->toBe(['HeadObject']);
});

it('rejects a cached copy after the object changes', function () {
    $hash = cacheTestUpload($this);
    $this->etag = 'object-version-2';
    expect(app(ArtifactFileCache::class)->get($this->disk, 'signed/test.ipa', $hash, 7))->toBeNull();
});

it('rejects local corruption even when its length stays the same', function () {
    $hash = cacheTestUpload($this);
    file_put_contents($this->cacheRoot.'/'.$hash.'.ipa', 'changed');
    expect(app(ArtifactFileCache::class)->get($this->disk, 'signed/test.ipa', $hash, 7))->toBeNull();
});

it('evicts expired entries when storing another file', function () {
    $hash = cacheTestUpload($this);
    touch($this->cacheRoot.'/'.$hash.'.ipa', time() - 90000);
    cacheTestUpload($this, 'another');
    expect(is_file($this->cacheRoot.'/'.$hash.'.ipa'))->toBeFalse();
});

it('does not exceed its configured disk budget', function () {
    config(['storefront.artifacts.file_cache_max_bytes' => 7]);
    $first = cacheTestUpload($this);
    $second = cacheTestUpload($this, 'another');
    expect(app(ArtifactFileCache::class)->get($this->disk, 'signed/test.ipa', $first, 7))->not->toBeNull()
        ->and(app(ArtifactFileCache::class)->get($this->disk, 'signed/test.ipa', $second, 7))->toBeNull();
});

it('falls back when disabled', function () {
    $hash = cacheTestUpload($this);
    config(['storefront.artifacts.file_cache_enabled' => false]);
    expect(app(ArtifactFileCache::class)->get($this->disk, 'signed/test.ipa', $hash, 7))->toBeNull();
});
