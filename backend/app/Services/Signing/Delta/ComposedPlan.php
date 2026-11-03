<?php

namespace App\Services\Signing\Delta;

/**
 * How a delta build's IPA is put together: byte ranges of the base IPA and of the pack, then the
 * generated central directory. Known before the first byte is sent, so Content-Length and Range work.
 */
final class ComposedPlan
{
    public const BASE = 0;

    public const PACK = 1;

    public const MEMORY = 2;

    public function __construct(
        /** @var list<array{0: int, 1: int, 2: int}> [source, offset, length]; MEMORY offsets index $memory. */
        public readonly array $segments,
        /** Central directory records and the end record. */
        public readonly string $memory,
        public readonly int $size,
        public readonly int $entries,
        public readonly int $packEntries,
        public readonly string $baseSha256,
    ) {}

    /**
     * Writes the bytes [start, end] of the composed IPA to the output.
     *
     * @param  callable(string): void  $write
     * @param  callable(): bool  $alive  false once the client has gone
     */
    public function stream(string $basePath, string $packPath, int $start, int $end, callable $write, callable $alive): void
    {
        $handles = [];
        try {
            $position = 0;
            foreach ($this->segments as [$source, $offset, $length]) {
                $segmentEnd = $position + $length - 1;
                if ($segmentEnd >= $start && $position <= $end) {
                    $from = max($start, $position) - $position;
                    $count = min($end, $segmentEnd) - $position - $from + 1;
                    if ($source === self::MEMORY) {
                        $write(substr($this->memory, $offset + $from, $count));
                    } else {
                        $handles[$source] ??= @fopen($source === self::BASE ? $basePath : $packPath, 'rb') ?: throw new DeltaException('Cannot open a delta source.');
                        fseek($handles[$source], $offset + $from);
                        while ($count > 0 && $alive()) {
                            $chunk = fread($handles[$source], min(1024 * 1024, $count));
                            if ($chunk === false || $chunk === '') {
                                throw new DeltaException('A delta source ended early.');
                            }
                            $write($chunk);
                            $count -= strlen($chunk);
                        }
                    }
                }
                $position += $length;
                if ($position > $end || ! $alive()) {
                    break;
                }
            }
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
    }
}
