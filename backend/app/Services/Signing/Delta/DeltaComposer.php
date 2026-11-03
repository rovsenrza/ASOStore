<?php

namespace App\Services\Signing\Delta;

/**
 * Plans the IPA of a delta build from the artifact's original IPA and the runner's pack
 * (docs/adr/DELTA_BUILDS.md, docs/adr/DELTA_V3.md): every entry's raw bytes come from the base, the pack
 * or, for a segmented (kind 2) entry of a `delta-v2` pack, from chunks of the pack's team blob and of the
 * artifact's shared core. Only local headers of segmented entries and the central directory are generated.
 */
final class DeltaComposer
{
    /** The pack entry that lists the final IPA's entries; never part of the final IPA. */
    public const MANIFEST = 'Payload/.delta-manifest.json';

    /** A `delta-v2` pack's stored concatenation of team chunks; never part of the final IPA. */
    public const TEAM_BLOB = 'Payload/.delta-team.bin';

    /** Date and time of generated entries: 1980-01-01 00:00, the first valid DOS date. */
    private const DOS_TIME = 0;

    private const DOS_DATE = 0x21;

    /** Record pair of a generated entry when the manifest names none: Unix, regular file 0644. */
    private const DEFAULT_MADE_BY = 0x0314;

    private const DEFAULT_EXTERNAL = 0x81A40000;

    /**
     * The sha256 of the core a pack's segmented entries read from, or null when the pack needs none.
     * Lets a caller find the core file before planning.
     *
     * @throws DeltaException when the pack has no readable manifest
     */
    public static function requiredCore(string $packPath): ?string
    {
        [, $manifest] = self::manifest($packPath, ZipDirectory::read($packPath));
        if ($manifest['version'] !== 2 || ! is_string($manifest['core_sha256'] ?? null)) {
            return null;
        }
        foreach ($manifest['entries'] as $entry) {
            $segments = is_array($entry) && is_array($entry[2] ?? null) && is_array($entry[2]['segments'] ?? null) ? $entry[2]['segments'] : [];
            foreach ($segments as $segment) {
                if (is_array($segment) && ($segment[0] ?? null) === 'c') {
                    return strtolower($manifest['core_sha256']);
                }
            }
        }

        return null;
    }

    /**
     * With $checkHeaders every entry it will copy is looked up at the offset the directory gives, so a
     * damaged file is found when the build is verified rather than by a customer's phone.
     *
     * $corePath is the local copy of the core named by a `delta-v2` manifest; needed only when a
     * segmented entry reads from it. With $changedOnly the entries taken from the base are left out:
     * the result is the IPA's changed entries alone (what the verifier inspects).
     *
     * @throws DeltaException when the pack and the base do not describe a valid IPA
     */
    public static function plan(string $basePath, string $packPath, bool $checkHeaders = false, ?string $corePath = null, bool $changedOnly = false): ComposedPlan
    {
        $pack = ZipDirectory::read($packPath);
        [, $manifest] = self::manifest($packPath, $pack);
        $version = $manifest['version'];
        // zsign's own records carry no Unix modes; the entries taken from the base are written the same way.
        $pair = null;
        if (isset($manifest['made_by']) || isset($manifest['external'])) {
            $pair = [$manifest['made_by'] ?? null, $manifest['external'] ?? null];
            if (! is_int($pair[0]) || $pair[0] < 0 || $pair[0] > 0xFFFF || ! is_int($pair[1]) || $pair[1] < 0 || $pair[1] > 0xFFFFFFFF) {
                throw new DeltaException('The pack manifest is not valid.');
            }
        }

        $team = null;
        $core = null;
        $coreSha256 = null;
        if ($version === 2) {
            $coreSha256 = $manifest['core_sha256'] ?? null;
            if ($coreSha256 !== null && (! is_string($coreSha256) || preg_match('/\A[0-9a-fA-F]{64}\z/', $coreSha256) !== 1)) {
                throw new DeltaException('The pack manifest is not valid.');
            }
            $coreSha256 = $coreSha256 === null ? null : strtolower($coreSha256);
            if (isset($pack['records'][self::TEAM_BLOB])) {
                $team = self::storedData($packPath, $pack['records'][self::TEAM_BLOB]);
            }
        }
        $base = ZipDirectory::read($basePath);

        $segments = [];
        $memory = '';
        $directory = '';
        $cursor = 0;
        $fromPack = 0;
        $count = 0;
        $seen = [];
        $segmented = [];
        $coreUsed = false;
        $used = [ComposedPlan::BASE => [], ComposedPlan::PACK => []];
        foreach ($manifest['entries'] as $entry) {
            $kind = is_array($entry) ? ($entry[1] ?? null) : null;
            $valid = is_array($entry) && array_is_list($entry) && is_string($entry[0] ?? null) && $entry[0] !== '' && (
                (count($entry) === 2 && in_array($kind, [0, 1], true))
                || ($version === 2 && count($entry) === 3 && $kind === 2 && is_array($entry[2]))
            );
            if (! $valid) {
                throw new DeltaException('The pack manifest is not valid.');
            }
            $name = $entry[0];
            if ($name === self::MANIFEST || $name === self::TEAM_BLOB || isset($seen[$name])) {
                throw new DeltaException('The manifest repeats or hides an entry.');
            }
            $seen[$name] = true;

            if ($kind === 2) {
                $fromPack++;
                [$parts, $crc, $size, $compressed] = self::segments($name, $entry[2], $team, $coreSha256, $corePath);
                $flags = preg_match('/[^\x00-\x7F]/', $name) === 1 ? 0x800 : 0;
                $header = pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, 8, self::DOS_TIME, self::DOS_DATE, $crc, $compressed, $size, strlen($name), 0).$name;
                self::append($segments, ComposedPlan::MEMORY, strlen($memory), strlen($header));
                $memory .= $header;
                foreach ($parts as [$source, $offset, $length]) {
                    self::append($segments, $source, $offset, $length);
                }
                $coreUsed = $coreUsed || in_array(ComposedPlan::CORE, array_column($parts, 0), true);
                $segmented[] = ['name' => $name, 'crc' => $crc, 'size' => $size, 'segments' => $parts];
                $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, $pair[0] ?? self::DEFAULT_MADE_BY, 20, $flags, 8, self::DOS_TIME, self::DOS_DATE,
                    $crc, $compressed, $size, strlen($name), 0, 0, 0, 0, $pair[1] ?? self::DEFAULT_EXTERNAL, $cursor).$name;
                $extent = strlen($header) + $compressed;
            } else {
                $record = ($kind === 1 ? $pack['records'] : $base['records'])[$name] ?? throw new DeltaException("The manifest names {$name}, which its source lacks.");
                if ($kind === 0 && $changedOnly) {
                    continue;
                }
                $fromPack += $kind;
                $used[$kind === 1 ? ComposedPlan::PACK : ComposedPlan::BASE][] = $record;
                self::append($segments, $kind === 1 ? ComposedPlan::PACK : ComposedPlan::BASE, $record->localOffset, $record->extent);
                $raw = substr_replace($record->raw, pack('V', $cursor), 42, 4);
                if ($kind === 0 && $pair !== null) {
                    $raw = substr_replace(substr_replace($raw, pack('v', $pair[0]), 4, 2), pack('V', $pair[1]), 38, 4);
                }
                $directory .= $raw;
                $extent = $record->extent;
            }
            $count++;
            $cursor += $extent;
            if ($cursor >= 0xFFFFFFFF) {
                throw new DeltaException('The composed IPA would need zip64.');
            }
        }
        if ($count === 0) {
            throw new DeltaException('The composed IPA would have no entries.');
        }

        if ($checkHeaders) {
            ZipDirectory::checkLocalHeaders($basePath, $used[ComposedPlan::BASE]);
            ZipDirectory::checkLocalHeaders($packPath, $used[ComposedPlan::PACK]);
        }

        $directoryAt = strlen($memory);
        $memory .= $directory.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($directory), $cursor, 0);
        $segments[] = [ComposedPlan::MEMORY, $directoryAt, strlen($memory) - $directoryAt];
        $size = $cursor + strlen($memory) - $directoryAt;
        if ($size >= 0xFFFFFFFF) {
            throw new DeltaException('The composed IPA would need zip64.');
        }

        return new ComposedPlan($segments, $memory, $size, $count, $fromPack, strtolower($manifest['base_sha256']), $version, $segmented,
            $coreUsed ? $coreSha256 : null);
    }

    /**
     * The manifest record and the decoded, shape-checked manifest (version 1 or 2).
     *
     * @param  array{records: array<string, ZipRecord>}  $pack
     * @return array{0: ZipRecord, 1: array<string, mixed>}
     */
    private static function manifest(string $packPath, array $pack): array
    {
        $record = $pack['records'][self::MANIFEST] ?? throw new DeltaException('The pack has no manifest.');
        $manifest = json_decode(ZipDirectory::readStored($packPath, $record), true);
        if (! is_array($manifest) || ! in_array($manifest['version'] ?? null, [1, 2], true) || ! is_string($manifest['base_sha256'] ?? null)
            || ! is_array($manifest['entries'] ?? null) || $manifest['entries'] === [] || count($manifest['entries']) > ZipDirectory::MAX_ENTRIES) {
            throw new DeltaException('The pack manifest is not valid.');
        }

        return [$record, $manifest];
    }

    /**
     * Where a stored entry's data lies in its file: [offset, length]. The team blob is read in place.
     *
     * @return array{0: int, 1: int}
     */
    private static function storedData(string $path, ZipRecord $record): array
    {
        if ($record->method !== 0 || $record->size !== $record->compressedSize || ($record->flags & 1) !== 0) {
            throw new DeltaException("{$record->name} is not a plain stored entry.");
        }
        $handle = @fopen($path, 'rb') ?: throw new DeltaException("Cannot open {$path}.");
        try {
            fseek($handle, $record->localOffset);
            $head = (string) fread($handle, 30);
            if (strlen($head) !== 30 || substr($head, 0, 4) !== "PK\x03\x04") {
                throw new DeltaException("No local header for {$record->name}.");
            }
            $lengths = unpack('vname/vextra', $head, 26);
            if ($lengths['name'] !== strlen($record->name) || (string) fread($handle, $lengths['name']) !== $record->name) {
                throw new DeltaException("The local header of {$record->name} names another entry.");
            }
            $offset = $record->localOffset + 30 + $lengths['name'] + $lengths['extra'];
            if ($offset + $record->size > $record->localOffset + $record->extent) {
                throw new DeltaException("{$record->name} runs past its entry.");
            }

            return [$offset, $record->size];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Checks a segmented entry and maps its segments to file ranges.
     *
     * @param  array<mixed>  $spec
     * @param  array{0: int, 1: int}|null  $team
     * @return array{0: list<array{0: int, 1: int, 2: int}>, 1: int, 2: int, 3: int} segments, crc, size, compressed size
     */
    private static function segments(string $name, array $spec, ?array $team, ?string $coreSha256, ?string $corePath): array
    {
        $crc = $spec['crc'] ?? null;
        $size = $spec['size'] ?? null;
        $list = $spec['segments'] ?? null;
        if (str_ends_with($name, '/') || strlen($name) > 0xFFFF || ! is_int($crc) || $crc < 0 || $crc > 0xFFFFFFFF
            || ! is_int($size) || $size < 0 || $size >= 0xFFFFFFFF || ! is_array($list) || $list === [] || ! array_is_list($list)) {
            throw new DeltaException("The segmented entry {$name} is not valid.");
        }
        $parts = [];
        $compressed = 0;
        foreach ($list as $segment) {
            if (! is_array($segment) || count($segment) !== 3 || ! in_array($segment[0] ?? null, ['t', 'c'], true)
                || ! is_int($segment[1] ?? null) || ! is_int($segment[2] ?? null) || $segment[1] < 0 || $segment[2] < 1) {
                throw new DeltaException("The segmented entry {$name} is not valid.");
            }
            [$which, $offset, $length] = $segment;
            if ($which === 't') {
                if ($team === null) {
                    throw new DeltaException("{$name} reads from a team blob the pack lacks.");
                }
                if ($offset + $length > $team[1]) {
                    throw new DeltaException("A segment of {$name} lies outside the team blob.");
                }
                $parts[] = [ComposedPlan::PACK, $team[0] + $offset, $length];
            } else {
                if ($coreSha256 === null) {
                    throw new DeltaException("{$name} reads from a core the manifest does not name.");
                }
                $coreSize = $corePath === null ? false : @filesize($corePath);
                if ($coreSize === false) {
                    throw new DeltaException("The core {$coreSha256} is not available.");
                }
                if ($offset + $length > $coreSize) {
                    throw new DeltaException("A segment of {$name} lies outside the core.");
                }
                $parts[] = [ComposedPlan::CORE, $offset, $length];
            }
            $compressed += $length;
            if ($compressed >= 0xFFFFFFFF) {
                throw new DeltaException("{$name} would need zip64.");
            }
        }

        return [$parts, $crc, $size, $compressed];
    }

    /**
     * Adds a range, joining it to the previous one when the two are neighbours in the same file, so a
     * run of untouched entries becomes one long read.
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $segments
     */
    private static function append(array &$segments, int $source, int $offset, int $length): void
    {
        $last = array_key_last($segments);
        if ($last !== null && $segments[$last][0] === $source && $segments[$last][1] + $segments[$last][2] === $offset) {
            $segments[$last][2] += $length;

            return;
        }
        $segments[] = [$source, $offset, $length];
    }
}
