<?php

namespace App\Services\Artifacts;

use App\Jobs\HydrateArtifactJob;
use App\Models\AppArtifact;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

/**
 * Cold originals: catalog IPAs nobody has installed for a while live only in the object bucket
 * (same key as their local path, e.g. originals/ab/<sha256>.ipa) and are brought back to the local
 * disk the moment something needs them — an install, a signing job, an admin tool.
 *
 * Local NVMe stays the primary store (signing and delta packs need a seekable local file);
 * `artifacts:cold` moves only files whose bucket copy is verified, and every way of reading an
 * original calls ensureLocal() first.
 */
class ColdStorage
{
    public function enabled(): bool
    {
        return (bool) config('storefront.cold_storage.enabled') && $this->localDisk() !== null;
    }

    /** The artifacts disk, when it is local (cold storage means nothing on an S3 artifacts disk). */
    public function localDisk(): ?FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('artifacts');

        return $disk->getAdapter() instanceof LocalFilesystemAdapter ? $disk : null;
    }

    public function bucket(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk('artifacts_cold');
    }

    public function isLocal(AppArtifact $artifact): bool
    {
        return is_file($this->localPath($artifact));
    }

    public function localPath(AppArtifact $artifact): string
    {
        return Storage::disk($artifact->storage_disk)->path($artifact->storage_path);
    }

    /**
     * Makes sure the original is on the local disk, downloading and verifying it from the bucket
     * if needed. Returns false only when it is missing and cannot be brought back.
     */
    public function ensureLocal(AppArtifact $artifact): bool
    {
        if ($artifact->storage_disk !== 'artifacts' || $this->localDisk() === null || $this->isLocal($artifact)) {
            return true;
        }
        if (! $this->enabled()) {
            return false;
        }

        return $this->hydratePath($artifact->storage_path, $artifact->sha256, (int) $artifact->size_bytes);
    }

    /**
     * For readers that only know a path on the artifacts disk (LocalArtifactFile): the artifact row
     * is looked up to verify the download.
     */
    public function ensureLocalPath(string $relative): void
    {
        $disk = $this->localDisk();
        if ($disk === null || ! $this->enabled() || is_file($disk->path($relative))) {
            return;
        }
        $artifact = AppArtifact::query()->where('storage_disk', 'artifacts')->where('storage_path', $relative)->first();
        if ($artifact !== null) {
            $this->hydratePath($relative, $artifact->sha256, (int) $artifact->size_bytes);
        }
    }

    /** Starts bringing the original back in the background (an install was asked for). */
    public function prefetch(AppArtifact $artifact): void
    {
        if ($artifact->storage_disk === 'artifacts' && $this->enabled() && ! $this->isLocal($artifact)) {
            HydrateArtifactJob::dispatch($artifact->id)->afterCommit();
        }
    }

    /**
     * Whether the bucket holds this original byte for byte as far as size tells (a full hash would
     * mean downloading it); the hash is checked again on every download.
     */
    public function bucketHasCopy(AppArtifact $artifact): bool
    {
        try {
            $bucket = $this->bucket();

            return $bucket->exists($artifact->storage_path) && (int) $bucket->size($artifact->storage_path) === (int) $artifact->size_bytes;
        } catch (Throwable) {
            return false;
        }
    }

    private function hydratePath(string $relative, string $sha256, int $size): bool
    {
        $disk = $this->localDisk() ?? throw new RuntimeException('The artifacts disk is not local.');
        $target = $disk->path($relative);
        $directory = dirname($target);
        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create '.$directory);
        }

        // One download per file: others wait for it and then find the file in place.
        $lock = fopen($target.'.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot lock '.$target);
        }
        flock($lock, LOCK_EX);
        $part = $target.'.part-'.getmypid();
        try {
            if (is_file($target)) {
                return true;
            }
            if (! ArtifactFileCache::diskHasRoom($directory, $size) && ! $this->diskHasBareRoom($directory, $size)) {
                Log::error('artifact.hydrate_no_room', ['path' => $relative, 'bytes' => $size]);

                return false;
            }
            $started = microtime(true);
            $source = $this->bucket()->readStream($relative);
            if ($source === null) {
                Log::error('artifact.hydrate_missing_in_bucket', ['path' => $relative]);

                return false;
            }
            $out = fopen($part, 'wb');
            if ($out === false) {
                throw new RuntimeException('Cannot write '.$part);
            }
            $hash = hash_init('sha256');
            $written = 0;
            try {
                while (! feof($source)) {
                    $chunk = fread($source, 8 * 1024 * 1024);
                    if ($chunk === false) {
                        throw new RuntimeException('Reading the bucket copy failed.');
                    }
                    hash_update($hash, $chunk);
                    $written += strlen($chunk);
                    if (fwrite($out, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException('Writing the local copy failed.');
                    }
                }
            } finally {
                fclose($out);
                is_resource($source) && fclose($source);
            }
            if ($written !== $size || ! hash_equals($sha256, hash_final($hash))) {
                Log::error('artifact.hydrate_mismatch', ['path' => $relative, 'bytes' => $written, 'expected' => $size]);

                return false;
            }
            chmod($part, 0640);
            if (! rename($part, $target)) {
                throw new RuntimeException('Cannot move '.$part);
            }
            Log::info('artifact.hydrated', ['path' => $relative, 'bytes' => $size, 'seconds' => round(microtime(true) - $started, 1)]);

            return true;
        } finally {
            @unlink($part);
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($target.'.lock');
        }
    }

    /** A customer's install may use the reserve the speculative work leaves free, but never the last 2 GB. */
    private function diskHasBareRoom(string $directory, int $bytes): bool
    {
        $free = @disk_free_space($directory);

        return $free === false || $free - $bytes > 2 * 1024 ** 3;
    }
}
