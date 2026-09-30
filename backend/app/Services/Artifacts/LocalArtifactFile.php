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

        $path = tempnam(sys_get_temp_dir(), 'ipa');
        $source = $disk->readStream($relative);
        if ($path === false || $source === null) {
            throw new RuntimeException('The artifact file cannot be read.');
        }
        chmod($path, 0600);
        $target = fopen($path, 'wb');
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        return new self($path, true);
    }

    public function release(): void
    {
        if ($this->temporary && is_file($this->path)) {
            unlink($this->path);
        }
    }
}
