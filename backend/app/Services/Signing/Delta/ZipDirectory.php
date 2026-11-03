<?php

namespace App\Services\Signing\Delta;

/**
 * The central directory of a ZIP file, read without opening the archive's entries. Only what delta
 * builds need: names, where each entry's bytes (local header, data, data descriptor) lie, and the
 * raw central record to copy. zip64 and anything ambiguous is refused (DeltaException).
 */
final class ZipDirectory
{
    public const MAX_ENTRIES = 65535;

    private const END_SIGNATURE = "PK\x05\x06";

    private const ZIP64_LOCATOR = "PK\x06\x07";

    private const CENTRAL_SIGNATURE = "PK\x01\x02";

    private const LOCAL_SIGNATURE = "PK\x03\x04";

    /**
     * @return array{records: array<string, ZipRecord>, order: list<string>, directoryOffset: int, directorySize: int, size: int}
     */
    public static function read(string $path): array
    {
        $handle = @fopen($path, 'rb') ?: throw new DeltaException("Cannot open {$path}.");
        try {
            $size = filesize($path);
            if ($size === false || $size < 22 || $size >= 0xFFFFFFFF) {
                throw new DeltaException('The archive is empty or too large for delta builds.');
            }
            $tailLength = (int) min($size, 22 + 0xFFFF);
            fseek($handle, $size - $tailLength);
            $tail = (string) fread($handle, $tailLength);
            $at = strrpos($tail, self::END_SIGNATURE);
            if ($at === false || $at + 22 > strlen($tail)) {
                throw new DeltaException('No end-of-central-directory record.');
            }
            $end = unpack('vdisk/vstart/vlocal/vtotal/Vsize/Voffset/vcomment', $tail, $at + 4);
            if ($end['disk'] !== 0 || $end['start'] !== 0 || $end['local'] !== $end['total'] || $at + 22 + $end['comment'] !== strlen($tail)) {
                throw new DeltaException('Unsupported end-of-central-directory record.');
            }
            if ($end['total'] === 0xFFFF || $end['size'] === 0xFFFFFFFF || $end['offset'] === 0xFFFFFFFF
                || ($at >= 20 && substr($tail, $at - 20, 4) === self::ZIP64_LOCATOR)) {
                throw new DeltaException('zip64 archives are not supported.');
            }
            if ($end['total'] < 1 || $end['total'] > self::MAX_ENTRIES || $end['offset'] + $end['size'] > $size - $tailLength + $at) {
                throw new DeltaException('The central directory is out of range.');
            }

            fseek($handle, $end['offset']);
            $directory = $end['size'] === 0 ? '' : (string) fread($handle, $end['size']);
            if (strlen($directory) !== $end['size']) {
                throw new DeltaException('The central directory is truncated.');
            }

            return self::parse($directory, $end['total'], $end['offset'], $size);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array{records: array<string, ZipRecord>, order: list<string>, directoryOffset: int, directorySize: int, size: int}
     */
    private static function parse(string $directory, int $count, int $directoryOffset, int $size): array
    {
        $records = [];
        $order = [];
        $cursor = 0;
        for ($i = 0; $i < $count; $i++) {
            if (substr($directory, $cursor, 4) !== self::CENTRAL_SIGNATURE || $cursor + 46 > strlen($directory)) {
                throw new DeltaException('Broken central directory record.');
            }
            $f = unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Voffset', $directory, $cursor + 4);
            $length = 46 + $f['name'] + $f['extra'] + $f['comment'];
            if ($cursor + $length > strlen($directory)) {
                throw new DeltaException('Broken central directory record.');
            }
            if ($f['compressed'] === 0xFFFFFFFF || $f['size'] === 0xFFFFFFFF || $f['offset'] === 0xFFFFFFFF || $f['disk'] !== 0) {
                throw new DeltaException('zip64 entries are not supported.');
            }
            $name = substr($directory, $cursor + 46, $f['name']);
            if ($name === '' || isset($records[$name])) {
                throw new DeltaException('Empty or duplicate entry name.');
            }
            if ($f['offset'] >= $directoryOffset) {
                throw new DeltaException('An entry starts inside the central directory.');
            }
            $records[$name] = new ZipRecord(
                $name, $f['flags'], $f['method'], $f['crc'], $f['compressed'], $f['size'], $f['external'], $f['offset'],
                substr($directory, $cursor, $length), 0,
            );
            $order[] = $name;
            $cursor += $length;
        }
        if ($cursor !== strlen($directory)) {
            throw new DeltaException('Trailing bytes in the central directory.');
        }

        // An entry's extent runs up to the next one's local header (or the directory): that takes in
        // a data descriptor and any padding with it.
        $byOffset = $order;
        usort($byOffset, fn (string $a, string $b) => $records[$a]->localOffset <=> $records[$b]->localOffset);
        foreach ($byOffset as $index => $name) {
            $next = $byOffset[$index + 1] ?? null;
            $end = $next === null ? $directoryOffset : $records[$next]->localOffset;
            if ($end <= $records[$name]->localOffset) {
                throw new DeltaException('Two entries share a local header.');
            }
            $records[$name]->extent = $end - $records[$name]->localOffset;
            if ($records[$name]->extent < 30 + strlen($name) + $records[$name]->compressedSize) {
                throw new DeltaException('An entry is shorter than its own data.');
            }
        }

        return ['records' => $records, 'order' => $order, 'directoryOffset' => $directoryOffset, 'directorySize' => strlen($directory), 'size' => $size];
    }

    /**
     * Checks that each given entry really starts with a local header carrying its own name.
     *
     * @param  list<ZipRecord>  $records
     */
    public static function checkLocalHeaders(string $path, array $records): void
    {
        $handle = @fopen($path, 'rb') ?: throw new DeltaException("Cannot open {$path}.");
        try {
            foreach ($records as $record) {
                fseek($handle, $record->localOffset);
                $head = (string) fread($handle, 30);
                if (strlen($head) !== 30 || substr($head, 0, 4) !== self::LOCAL_SIGNATURE) {
                    throw new DeltaException("No local header for {$record->name}.");
                }
                $lengths = unpack('vname/vextra', $head, 26);
                if ($lengths['name'] !== strlen($record->name) || (string) fread($handle, $lengths['name']) !== $record->name) {
                    throw new DeltaException("The local header of {$record->name} names another entry.");
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** The stored bytes of one uncompressed (method 0) entry, such as the pack manifest. */
    public static function readStored(string $path, ZipRecord $record, int $limit = 64 * 1024 * 1024): string
    {
        if ($record->method !== 0 || $record->size !== $record->compressedSize || $record->size > $limit || ($record->flags & 1) !== 0) {
            throw new DeltaException("{$record->name} is not a plain stored entry.");
        }
        $handle = @fopen($path, 'rb') ?: throw new DeltaException("Cannot open {$path}.");
        try {
            fseek($handle, $record->localOffset);
            $head = (string) fread($handle, 30);
            if (strlen($head) !== 30 || substr($head, 0, 4) !== self::LOCAL_SIGNATURE) {
                throw new DeltaException("No local header for {$record->name}.");
            }
            $lengths = unpack('vname/vextra', $head, 26);
            fseek($handle, $record->localOffset + 30 + $lengths['name'] + $lengths['extra']);
            $data = $record->size === 0 ? '' : (string) fread($handle, $record->size);
            if (strlen($data) !== $record->size || crc32($data) !== $record->crc) {
                throw new DeltaException("{$record->name} is damaged.");
            }

            return $data;
        } finally {
            fclose($handle);
        }
    }
}
