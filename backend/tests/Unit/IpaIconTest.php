<?php

use App\Services\Catalog\CgbiPng;
use App\Services\Catalog\IpaIconExtractor;
use App\Services\Catalog\StoreMetadataLookup;
use Illuminate\Support\Facades\Http;

/** The way Xcode stores a PNG in an IPA: a CgBI chunk, BGRA premultiplied, raw deflate. */
function cgbiPng(array $rows, int $width, array $filters = []): string
{
    $chunk = fn (string $type, string $body) => pack('N', strlen($body)).$type.$body.pack('N', crc32($type.$body));
    $raw = '';
    $previous = array_fill(0, $width * 4, 0);
    foreach ($rows as $y => $pixels) {
        // $pixels: list of [r, g, b, a] straight colour; stored as premultiplied BGRA.
        $bytes = [];
        foreach ($pixels as [$r, $g, $b, $a]) {
            $bytes = array_merge($bytes, [intdiv($b * $a + 127, 255), intdiv($g * $a + 127, 255), intdiv($r * $a + 127, 255), $a]);
        }
        $filter = $filters[$y] ?? 0;
        $line = '';
        foreach ($bytes as $i => $byte) {
            $left = $i >= 4 ? $bytes[$i - 4] : 0;
            $up = $previous[$i];
            $line .= chr(match ($filter) {
                1 => ($byte - $left) & 0xFF,
                2 => ($byte - $up) & 0xFF,
                default => $byte,
            });
        }
        $raw .= chr($filter).$line;
        $previous = $bytes;
    }

    return "\x89PNG\r\n\x1a\n".$chunk('CgBI', pack('N', 0x50002008))
        .$chunk('IHDR', pack('NNCCCCC', $width, count($rows), 8, 6, 0, 0, 0))
        .$chunk('IDAT', gzdeflate($raw))
        .$chunk('IEND', '');
}

function pixel(string $png, int $x, int $y): array
{
    $image = imagecreatefromstring($png);
    $color = imagecolorat($image, $x, $y);

    return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF, (int) round((127 - (($color >> 24) & 0x7F)) * 255 / 127)];
}

it('turns an Xcode CgBI PNG into a standard PNG with the right colours', function () {
    $png = cgbiPng([[[255, 0, 0, 255], [0, 128, 0, 255]], [[0, 0, 255, 255], [10, 20, 30, 255]]], 2);

    $standard = CgbiPng::toStandard($png);

    expect($standard)->not->toBeNull()
        ->and(getimagesizefromstring($standard)[0])->toBe(2)
        ->and(pixel($standard, 0, 0))->toBe([255, 0, 0, 255])
        ->and(pixel($standard, 1, 0))->toBe([0, 128, 0, 255])
        ->and(pixel($standard, 0, 1))->toBe([0, 0, 255, 255])
        ->and(pixel($standard, 1, 1))->toBe([10, 20, 30, 255]);
});

it('undoes the row filters and the alpha premultiplication', function () {
    // Row 0 uses Sub, row 1 uses Up; the second pixel of row 0 is half transparent.
    $png = cgbiPng([[[200, 100, 50, 255], [200, 100, 50, 128]], [[40, 50, 60, 255], [70, 80, 90, 255]]], 2, [1, 2]);

    $standard = CgbiPng::toStandard($png);

    $half = pixel($standard, 1, 0);
    expect(pixel($standard, 0, 0))->toBe([200, 100, 50, 255])
        ->and(abs($half[0] - 200))->toBeLessThanOrEqual(2)
        ->and(abs($half[1] - 100))->toBeLessThanOrEqual(2)
        ->and(abs($half[2] - 50))->toBeLessThanOrEqual(2)
        ->and(abs($half[3] - 128))->toBeLessThanOrEqual(2)
        ->and(pixel($standard, 0, 1))->toBe([40, 50, 60, 255])
        ->and(pixel($standard, 1, 1))->toBe([70, 80, 90, 255]);
});

it('returns a standard PNG unchanged and refuses data that is not a PNG', function () {
    $image = imagecreatetruecolor(2, 2);
    ob_start();
    imagepng($image);
    $standard = (string) ob_get_clean();

    expect(CgbiPng::toStandard($standard))->toBe($standard)
        ->and(CgbiPng::toStandard('not a png'))->toBeNull()
        ->and(CgbiPng::toStandard(substr(cgbiPng([[[1, 2, 3, 255]]], 1), 0, 40)))->toBeNull();
});

it('picks the largest loose icon of an IPA', function () {
    $path = tempnam(sys_get_temp_dir(), 'ipa');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString('Payload/Demo.app/AppIcon60x60@2x.png', cgbiPng(array_fill(0, 2, array_fill(0, 2, [255, 0, 0, 255])), 2));
    $zip->addFromString('Payload/Demo.app/AppIcon76x76@2x~ipad.png', cgbiPng(array_fill(0, 4, array_fill(0, 4, [0, 255, 0, 255])), 4));
    $zip->addFromString('Payload/Demo.app/Other.png', 'ignored');
    $zip->close();

    $icon = (new IpaIconExtractor)->extract($path);
    @unlink($path);

    expect(getimagesizefromstring($icon)[0])->toBe(4)
        ->and(pixel($icon, 0, 0))->toBe([0, 255, 0, 255]);

    $empty = tempnam(sys_get_temp_dir(), 'ipa');
    $zip = new ZipArchive;
    $zip->open($empty, ZipArchive::OVERWRITE);
    $zip->addFromString('Payload/Demo.app/Info.plist', 'x');
    $zip->close();
    expect((new IpaIconExtractor)->extract($empty))->toBeNull();
    @unlink($empty);
});

it('reads name, developer, category and artwork from the store lookup', function () {
    config(['storefront.quick_publish.lookup_enabled' => true]);
    Http::fake(['itunes.apple.com/*' => Http::response(['results' => [[
        'trackName' => ' ChatGPT ', 'artistName' => 'OpenAI OpCo, LLC', 'primaryGenreName' => 'Productivity', 'trackId' => 6448311069,
        'artworkUrl512' => 'https://is1-ssl.mzstatic.com/a.png',
    ]]])]);

    expect((new StoreMetadataLookup)->find('com.openai.chat'))->toBe([
        'name' => 'ChatGPT', 'publisher' => 'OpenAI OpCo, LLC', 'category_slug' => 'productivity',
        'icon_url' => 'https://is1-ssl.mzstatic.com/a.png', 'app_store_id' => '6448311069',
    ]);
});

it('treats a missing app, an outage or a disabled lookup as no metadata', function () {
    config(['storefront.quick_publish.lookup_enabled' => true]);
    Http::fake(['itunes.apple.com/*' => Http::response(['results' => []])]);
    expect((new StoreMetadataLookup)->find('com.nobody.here'))->toBeNull();

    Http::fake(['itunes.apple.com/*' => Http::response('down', 503)]);
    expect((new StoreMetadataLookup)->find('com.openai.chat'))->toBeNull();

    Http::fake(fn () => throw new RuntimeException('connection refused'));
    expect((new StoreMetadataLookup)->find('com.openai.chat'))->toBeNull();

    config(['storefront.quick_publish.lookup_enabled' => false]);
    expect((new StoreMetadataLookup)->find('com.openai.chat'))->toBeNull();
});

it('fetches artwork only from Apple\'s own image host over HTTPS', function () {
    config(['storefront.quick_publish.lookup_enabled' => true]);
    Http::fake();
    $lookup = new StoreMetadataLookup;

    expect($lookup->icon('https://evil.example.com/a.png'))->toBeNull()
        ->and($lookup->icon('http://is1-ssl.mzstatic.com/a.png'))->toBeNull()
        ->and($lookup->icon('https://mzstatic.com.evil.example/a.png'))->toBeNull()
        ->and($lookup->icon(null))->toBeNull();
    Http::assertNothingSent();
});
