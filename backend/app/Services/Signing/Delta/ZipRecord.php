<?php

namespace App\Services\Signing\Delta;

/** One central directory record of a ZIP file, with the extent of the entry's bytes in that file. */
final class ZipRecord
{
    public function __construct(
        public readonly string $name,
        public readonly int $flags,
        public readonly int $method,
        public readonly int $crc,
        public readonly int $compressedSize,
        public readonly int $size,
        public readonly int $externalAttributes,
        public readonly int $localOffset,
        /** The whole record as stored, to be copied with a new local header offset. */
        public readonly string $raw,
        /** Bytes from the local header to the next entry (or to the directory): header, data, descriptor. */
        public int $extent,
    ) {}
}
