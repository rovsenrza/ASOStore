<?php

namespace App\Services\Catalog;

/**
 * Converts the PNGs Xcode ships inside an IPA ("CgBI", Apple-optimised) to standard PNGs.
 * They are stored as BGRA with premultiplied alpha, deflated without a zlib header, so
 * image libraries cannot read them. A PNG that is already standard is returned unchanged.
 */
final class CgbiPng
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /** @return string|null Standard PNG bytes; null when the data is not a PNG this can convert. */
    public static function toStandard(string $data): ?string
    {
        $chunks = self::chunks($data);
        if ($chunks === null) {
            return null;
        }
        if ($chunks[0][0] !== 'CgBI') {
            return $data;
        }

        $header = null;
        $compressed = '';
        foreach ($chunks as [$type, $body]) {
            if ($type === 'IHDR') {
                $header = $body;
            } elseif ($type === 'IDAT') {
                $compressed .= $body;
            }
        }
        if ($header === null || strlen($header) !== 13) {
            return null;
        }
        $ihdr = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $header);
        // The only layout Xcode writes: 8-bit RGBA, not interlaced.
        if ($ihdr['depth'] !== 8 || $ihdr['color'] !== 6 || $ihdr['interlace'] !== 0 || $ihdr['width'] < 1 || $ihdr['height'] < 1) {
            return null;
        }

        $raw = @gzinflate($compressed);
        $stride = $ihdr['width'] * 4;
        if ($raw === false || strlen($raw) !== ($stride + 1) * $ihdr['height']) {
            return null;
        }

        $out = '';
        $previous = array_fill(1, $stride, 0);
        for ($y = 0; $y < $ihdr['height']; $y++) {
            $offset = $y * ($stride + 1);
            $line = self::unfilter(ord($raw[$offset]), array_values(unpack('C*', substr($raw, $offset + 1, $stride))), $previous);
            if ($line === null) {
                return null;
            }
            $previous = $line;
            $out .= "\0".self::straightRgba($line);
        }

        return self::SIGNATURE
            .self::chunk('IHDR', pack('NNCCCCC', $ihdr['width'], $ihdr['height'], 8, 6, 0, 0, 0))
            .self::chunk('IDAT', (string) gzcompress($out, 9))
            .self::chunk('IEND', '');
    }

    /** @return list<array{0: string, 1: string}>|null */
    private static function chunks(string $data): ?array
    {
        if (! str_starts_with($data, self::SIGNATURE)) {
            return null;
        }
        $chunks = [];
        $offset = 8;
        $length = strlen($data);
        while ($offset + 12 <= $length) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            if ($offset + 12 + $size > $length) {
                return null;
            }
            $type = substr($data, $offset + 4, 4);
            $chunks[] = [$type, substr($data, $offset + 8, $size)];
            $offset += 12 + $size;
            if ($type === 'IEND') {
                break;
            }
        }

        return $chunks === [] ? null : $chunks;
    }

    /**
     * Reverses one PNG row filter. Arrays are 1-based (unpack), 4 bytes per pixel.
     *
     * @param  list<int>  $line  0-based filtered bytes
     * @param  array<int, int>  $previous  the previous unfiltered row, 1-based
     * @return array<int, int>|null 1-based unfiltered row
     */
    private static function unfilter(int $filter, array $line, array $previous): ?array
    {
        $row = [];
        foreach ($line as $index => $byte) {
            $i = $index + 1;
            $left = $i > 4 ? $row[$i - 4] : 0;
            $up = $previous[$i];
            $upLeft = $i > 4 ? $previous[$i - 4] : 0;
            $row[$i] = match ($filter) {
                0 => $byte,
                1 => ($byte + $left) & 0xFF,
                2 => ($byte + $up) & 0xFF,
                3 => ($byte + intdiv($left + $up, 2)) & 0xFF,
                4 => ($byte + self::paeth($left, $up, $upLeft)) & 0xFF,
                default => null,
            };
            if ($row[$i] === null) {
                return null;
            }
        }

        return $row;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    /**
     * BGRA with premultiplied alpha → RGBA with straight alpha.
     *
     * @param  array<int, int>  $row
     */
    private static function straightRgba(array $row): string
    {
        $out = '';
        for ($i = 1, $n = count($row); $i <= $n; $i += 4) {
            [$b, $g, $r, $a] = [$row[$i], $row[$i + 1], $row[$i + 2], $row[$i + 3]];
            if ($a > 0 && $a < 255) {
                $r = min(255, intdiv($r * 255 + intdiv($a, 2), $a));
                $g = min(255, intdiv($g * 255 + intdiv($a, 2), $a));
                $b = min(255, intdiv($b * 255 + intdiv($a, 2), $a));
            } elseif ($a === 0) {
                [$r, $g, $b] = [0, 0, 0];
            }
            $out .= chr($r).chr($g).chr($b).chr($a);
        }

        return $out;
    }

    private static function chunk(string $type, string $body): string
    {
        return pack('N', strlen($body)).$type.$body.pack('N', crc32($type.$body));
    }
}
