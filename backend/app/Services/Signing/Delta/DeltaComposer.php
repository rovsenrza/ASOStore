<?php

namespace App\Services\Signing\Delta;

/**
 * Plans the IPA of a delta build from the artifact's original IPA and the runner's pack
 * (docs/adr/DELTA_BUILDS.md): every entry's raw bytes come from one of the two files unchanged, and
 * only the central directory is generated.
 */
final class DeltaComposer
{
    /** The pack entry that lists the final IPA's entries; never part of the final IPA. */
    public const MANIFEST = 'Payload/.delta-manifest.json';

    /**
     * With $checkHeaders every entry it will copy is looked up at the offset the directory gives, so a
     * damaged file is found when the build is verified rather than by a customer's phone.
     *
     * @throws DeltaException when the pack and the base do not describe a valid IPA
     */
    public static function plan(string $basePath, string $packPath, bool $checkHeaders = false): ComposedPlan
    {
        $pack = ZipDirectory::read($packPath);
        $manifestRecord = $pack['records'][self::MANIFEST] ?? throw new DeltaException('The pack has no manifest.');
        $manifest = json_decode(ZipDirectory::readStored($packPath, $manifestRecord), true);
        if (! is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ! is_string($manifest['base_sha256'] ?? null)
            || ! is_array($manifest['entries'] ?? null) || $manifest['entries'] === [] || count($manifest['entries']) > ZipDirectory::MAX_ENTRIES) {
            throw new DeltaException('The pack manifest is not valid.');
        }
        // zsign's own records carry no Unix modes; the entries taken from the base are written the same way.
        $pair = null;
        if (isset($manifest['made_by']) || isset($manifest['external'])) {
            $pair = [$manifest['made_by'] ?? null, $manifest['external'] ?? null];
            if (! is_int($pair[0]) || $pair[0] < 0 || $pair[0] > 0xFFFF || ! is_int($pair[1]) || $pair[1] < 0 || $pair[1] > 0xFFFFFFFF) {
                throw new DeltaException('The pack manifest is not valid.');
            }
        }
        $base = ZipDirectory::read($basePath);

        $segments = [];
        $directory = '';
        $cursor = 0;
        $fromPack = 0;
        $seen = [];
        $used = [ComposedPlan::BASE => [], ComposedPlan::PACK => []];
        foreach ($manifest['entries'] as $entry) {
            if (! is_array($entry) || count($entry) !== 2 || ! is_string($entry[0]) || ! in_array($entry[1], [0, 1], true)) {
                throw new DeltaException('The pack manifest is not valid.');
            }
            [$name, $source] = $entry;
            if ($name === self::MANIFEST || isset($seen[$name])) {
                throw new DeltaException('The manifest repeats or hides an entry.');
            }
            $seen[$name] = true;

            $record = ($source === 1 ? $pack['records'] : $base['records'])[$name] ?? throw new DeltaException("The manifest names {$name}, which its source lacks.");
            $fromPack += $source;
            $used[$source === 1 ? ComposedPlan::PACK : ComposedPlan::BASE][] = $record;
            self::append($segments, $source === 1 ? ComposedPlan::PACK : ComposedPlan::BASE, $record->localOffset, $record->extent);
            $raw = substr_replace($record->raw, pack('V', $cursor), 42, 4);
            if ($source === 0 && $pair !== null) {
                $raw = substr_replace(substr_replace($raw, pack('v', $pair[0]), 4, 2), pack('V', $pair[1]), 38, 4);
            }
            $directory .= $raw;
            $cursor += $record->extent;
            if ($cursor >= 0xFFFFFFFF) {
                throw new DeltaException('The composed IPA would need zip64.');
            }
        }

        if ($checkHeaders) {
            ZipDirectory::checkLocalHeaders($basePath, $used[ComposedPlan::BASE]);
            ZipDirectory::checkLocalHeaders($packPath, $used[ComposedPlan::PACK]);
        }

        $count = count($manifest['entries']);
        $memory = $directory.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($directory), $cursor, 0);
        $segments[] = [ComposedPlan::MEMORY, 0, strlen($memory)];

        return new ComposedPlan($segments, $memory, $cursor + strlen($memory), $count, $fromPack, strtolower($manifest['base_sha256']));
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
