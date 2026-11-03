<?php

namespace App\Services\Signing\Delta;

use App\Models\DeltaCore;
use App\Services\Artifacts\ArtifactFileCache;
use App\Services\Artifacts\LocalArtifactFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Throwable;

/**
 * A core blob as a local file the composer can seek in (docs/adr/DELTA_V3.md, "Composition"): the file itself
 * on a local `signed` disk; otherwise the copy in ArtifactFileCache, fetched once from object storage. When
 * the cache will not take it (switched off, over budget), it is kept in a private directory of its own
 * (storefront.signing.delta_builds.core_cache_path), removed with the core (StorageJanitor).
 */
final class StoredDeltaCoreLocator implements DeltaCoreLocator
{
    public function __construct(private readonly ArtifactFileCache $cache) {}

    public function localPath(string $sha256): ?string
    {
        $sha256 = strtolower($sha256);
        if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
            return null;
        }
        $core = DeltaCore::query()->where('sha256', $sha256)->first();
        if ($core === null) {
            return null;
        }
        $disk = Storage::disk('signed');
        try {
            if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
                return $disk->exists($core->storage_path) ? $disk->path($core->storage_path) : null;
            }
            if (($cached = $this->cache->get($disk, $core->storage_path, $sha256, $core->size_bytes)) !== null) {
                return $cached;
            }
            $kept = self::keptPath($sha256);
            if (is_file($kept) && filesize($kept) === $core->size_bytes) {
                return $kept;
            }

            $copy = LocalArtifactFile::open($disk, $core->storage_path);
            try {
                if (filesize($copy->path) !== $core->size_bytes || ! hash_equals($sha256, (string) hash_file('sha256', $copy->path))) {
                    Log::error('delta.core_corrupt', ['sha256' => $sha256]);

                    return null;
                }
                $stream = fopen($copy->path, 'rb');
                if ($stream !== false) {
                    try {
                        $this->cache->store($disk, $core->storage_path, $sha256, $core->size_bytes, $stream);
                    } finally {
                        fclose($stream);
                    }
                }
                if (($cached = $this->cache->get($disk, $core->storage_path, $sha256, $core->size_bytes)) !== null) {
                    return $cached;
                }
                $directory = dirname($kept);
                if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    return null;
                }
                // Same directory as the final name: the rename is atomic, a concurrent reader never sees half a file.
                $temporary = $kept.'.'.bin2hex(random_bytes(4)).'.tmp';
                if (! @copy($copy->path, $temporary) || ! @rename($temporary, $kept)) {
                    @unlink($temporary);

                    return null;
                }
                chmod($kept, 0600);

                return $kept;
            } finally {
                $copy->release();
            }
        } catch (Throwable $exception) {
            Log::warning('delta.core_unavailable', ['sha256' => $sha256, 'error' => $exception->getMessage()]);

            return null;
        }
    }

    /** Where a core the file cache would not take is kept locally. */
    public static function keptPath(string $sha256): string
    {
        return rtrim((string) config('storefront.signing.delta_builds.core_cache_path', storage_path('app/private/delta-cores')), '/')
            .'/'.$sha256.'.bin';
    }
}
