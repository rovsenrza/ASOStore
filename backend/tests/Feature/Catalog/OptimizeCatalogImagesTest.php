<?php

use App\Models\CatalogApp;
use Illuminate\Support\Facades\Storage;

function storedImage(string $path, int $width, int $height, string $format): void
{
    $image = imagecreatetruecolor($width, $height);
    // Noise-free gradient: compresses like a real icon, not like a flat swatch.
    for ($y = 0; $y < $height; $y += 8) {
        imagefilledrectangle($image, 0, $y, $width, $y + 8, (int) imagecolorallocate($image, $y % 255, 90, 200 - $y % 150));
    }
    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image, null, 92);
    Storage::disk('public')->put($path, (string) ob_get_clean());
}

beforeEach(fn () => Storage::fake('public'));

it('re-encodes old PNG icons and JPEG banners as small WebP without touching updated_at', function () {
    $app = CatalogApp::factory()->create(['icon_path' => 'catalog/x/icon-old.png', 'banner_path' => 'catalog/x/banner-old.jpg']);
    storedImage('catalog/x/icon-old.png', 512, 512, 'png');
    storedImage('catalog/x/banner-old.jpg', 1600, 1000, 'jpeg');
    $updatedAt = (string) CatalogApp::query()->whereKey($app->id)->value('updated_at');
    $this->travel(2)->days();

    $this->artisan('catalog:optimize-images')->assertSuccessful();

    $fresh = CatalogApp::query()->findOrFail($app->id);
    expect($fresh->icon_path)->toEndWith('.webp')->not->toBe('catalog/x/icon-old.png')
        ->and($fresh->banner_path)->toEndWith('.webp')
        ->and(getimagesizefromstring(Storage::disk('public')->get($fresh->icon_path)))->toMatchArray([0 => 384, 1 => 384, 'mime' => 'image/webp'])
        ->and(getimagesizefromstring(Storage::disk('public')->get($fresh->banner_path)))->toMatchArray([0 => 1280, 1 => 800, 'mime' => 'image/webp'])
        ->and(Storage::disk('public')->size($fresh->icon_path))->toBeLessThan(Storage::disk('public')->size('catalog/x/icon-old.png'))
        ->and(Storage::disk('public')->size($fresh->banner_path))->toBeLessThan(Storage::disk('public')->size('catalog/x/banner-old.jpg'))
        ->and($fresh->getRawOriginal('updated_at'))->toBe($updatedAt);
    // The old files stay until --delete-originals, so a rollback needs no restore.
    Storage::disk('public')->assertExists(['catalog/x/icon-old.png', 'catalog/x/banner-old.jpg']);

    // Already optimised rows are left alone, so the command can run again.
    $this->artisan('catalog:optimize-images')->expectsOutputToContain('0 files re-encoded')->assertSuccessful();
    expect(CatalogApp::query()->findOrFail($app->id)->icon_path)->toBe($fresh->icon_path);
});

it('deletes the originals on request and skips files that are missing', function () {
    $app = CatalogApp::factory()->create(['icon_path' => 'catalog/y/icon-old.png', 'banner_path' => 'catalog/y/banner-gone.jpg']);
    storedImage('catalog/y/icon-old.png', 512, 512, 'png');

    $this->artisan('catalog:optimize-images --delete-originals')->expectsOutputToContain('1 files re-encoded, 1 skipped')->assertSuccessful();

    Storage::disk('public')->assertMissing('catalog/y/icon-old.png');
    expect(CatalogApp::query()->findOrFail($app->id)->banner_path)->toBe('catalog/y/banner-gone.jpg');
});

it('keeps a small source icon at its own size', function () {
    $app = CatalogApp::factory()->create(['icon_path' => 'catalog/z/icon-small.png']);
    storedImage('catalog/z/icon-small.png', 256, 256, 'png');

    $this->artisan('catalog:optimize-images')->assertSuccessful();

    $path = CatalogApp::query()->findOrFail($app->id)->icon_path;
    expect(getimagesizefromstring(Storage::disk('public')->get($path)))->toMatchArray([0 => 256, 1 => 256, 'mime' => 'image/webp']);
});
