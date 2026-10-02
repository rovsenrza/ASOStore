<?php

namespace App\Services\Artifacts;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

/** Private, bounded copies of uploaded objects. The object store remains authoritative. */
final class ArtifactFileCache
{
    /** @param resource $source */
    public function store(FilesystemAdapter $disk, string $relative, string $sha256, int $size, $source): void
    {
        if (! $this->eligible($disk, $sha256) || $size > $this->budget()) {
            return;
        }
        $temporary = null;
        $lock = null;
        try {
            $root = $this->root();
            if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
                throw new RuntimeException('Cannot create artifact cache.');
            }
            $etag = $disk->checksum($relative);
            $lock = fopen($root.'/.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock artifact cache.');
            }
            $used = 0;
            $files = glob($root.'/*.ipa') ?: [];
            foreach ($files as $file) {
                if (filemtime($file) < time() - $this->ttl()) {
                    $this->remove($file);
                } else {
                    $used += filesize($file);
                }
            }
            usort($files, fn ($a, $b) => (@filemtime($a) ?: 0) <=> (@filemtime($b) ?: 0));
            foreach ($files as $file) {
                if ($used + $size <= $this->budget()) {
                    break;
                }
                // Give active responses time to open their files.
                if (is_file($file) && filemtime($file) < time() - 300) {
                    $used -= filesize($file);
                    $this->remove($file);
                }
            }
            if ($used + $size > $this->budget()) {
                return;
            }
            $temporary = tempnam($root, '.upload-');
            chmod($temporary, 0600);
            $target = fopen($temporary, 'wb');
            rewind($source);
            try {
                stream_copy_to_stream($source, $target);
            } finally {
                fclose($target);
            }
            if (filesize($temporary) !== $size || ! hash_equals($sha256, hash_file('sha256', $temporary))) {
                throw new RuntimeException('Artifact cache copy did not match the upload.');
            }
            $path = $root.'/'.$sha256.'.ipa';
            file_put_contents($path.'.json', json_encode(['relative' => $relative, 'etag' => $etag], JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($path.'.json', 0600);
            rename($temporary, $path);
            $temporary = null;
        } catch (Throwable) {
            Log::warning('artifact.cache_store_failed');
        } finally {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    public function get(FilesystemAdapter $disk, string $relative, string $sha256, int $size): ?string
    {
        if (! $this->eligible($disk, $sha256)) {
            return null;
        }
        $path = $this->root().'/'.$sha256.'.ipa';
        try {
            if (! is_file($path) || ! is_file($path.'.json') || filemtime($path) < time() - $this->ttl() || filesize($path) !== $size) {
                return null;
            }
            $metadata = json_decode(file_get_contents($path.'.json'), true, flags: JSON_THROW_ON_ERROR);
            if (($metadata['relative'] ?? null) !== $relative || ($metadata['etag'] ?? null) !== $disk->checksum($relative)
                || ! hash_equals($sha256, hash_file('sha256', $path))) {
                return null;
            }
            touch($path);

            return $path;
        } catch (Throwable) {
            return null;
        }
    }

    private function eligible(FilesystemAdapter $disk, string $sha256): bool
    {
        return config('storefront.artifacts.file_cache_enabled', true)
            && ! $disk->getAdapter() instanceof LocalFilesystemAdapter
            && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1;
    }

    private function root(): string
    {
        return config('storefront.artifacts.file_cache_path', storage_path('app/private/artifact-cache'));
    }

    private function budget(): int
    {
        return max(0, (int) config('storefront.artifacts.file_cache_max_bytes', 8 * 1024 ** 3));
    }

    private function ttl(): int
    {
        return max(1, (int) config('storefront.artifacts.file_cache_ttl_seconds', 86400));
    }

    private function remove(string $path): void
    {
        @unlink($path);
        @unlink($path.'.json');
    }
}
