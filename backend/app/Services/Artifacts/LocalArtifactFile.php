<?php

namespace App\Services\Artifacts;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * A real filesystem path for a stored file, for tools that cannot read
 * streams (ZipArchive, hash_file, the malware scanner). Local disks give the
 * file itself; remote disks (S3) are copied to a private temporary file that
 * the caller removes with release().
 */
final class LocalArtifactFile
{
    private function __construct(
        public readonly string $path,
        private readonly bool $temporary,
    ) {}

    public static function open(FilesystemAdapter $disk, string $relative): self
    {
        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return new self($disk->path($relative), false);
        }

        $path = self::temporaryPath('ipa');
        $source = $disk->readStream($relative);
        if ($source === null) {
            @unlink($path);
            throw new RuntimeException('The artifact file cannot be read.');
        }
        $target = fopen($path, 'wb');
        try {
            if ($target === false || stream_copy_to_stream($source, $target) === false) {
                throw new RuntimeException('The artifact file cannot be copied.');
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            fclose($source);
            if (is_resource($target)) {
                fclose($target);
            }
        }

        return new self($path, true);
    }

    /**
     * A new private file for a temporary IPA copy. They live in one directory, so a copy
     * left behind by a killed worker is found and removed (StorageJanitor).
     */
    public static function temporaryPath(string $prefix): string
    {
        $directory = (string) config('storefront.build_storage.temp_path', storage_path('app/private/tmp'));
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The temporary directory cannot be created.');
        }
        $path = tempnam($directory, $prefix);
        if ($path === false) {
            throw new RuntimeException('No temporary file available.');
        }
        chmod($path, 0600);

        return $path;
    }

    public function release(): void
    {
        if ($this->temporary && is_file($this->path)) {
            unlink($this->path);
        }
    }
}
