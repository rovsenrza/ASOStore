<?php

namespace App\Services\Catalog;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Normalises catalog media with GD. Icons and banners are stored as small WebP files sized for
 * the screens that show them (an icon is at most about 110 pt, a banner about one phone width),
 * because the app loads dozens of them on Home; screenshots stay JPEGs no wider than the
 * configured maximum. Re-encoding also drops any metadata (EXIF, location) the original carried.
 */
class CatalogImageService
{
    /**
     * @return array{path: string, width: int, height: int}
     */
    public function storeIcon(UploadedFile $file, string $appPublicId): array
    {
        $image = $this->load($file);
        [$width, $height] = [imagesx($image), imagesy($image)];

        if ($width !== $height || $width < 512) {
            throw $this->invalid('icon', 'Иконка должна быть квадратной, не меньше 512×512 пикселей.');
        }

        return $this->iconFrom($image, $appPublicId);
    }

    /**
     * @return array{path: string, width: int, height: int}
     */
    public function storeScreenshot(UploadedFile $file, string $appPublicId): array
    {
        $image = $this->load($file);
        [$width, $height] = [imagesx($image), imagesy($image)];

        if ($width < 320 || $height < 320) {
            throw $this->invalid('screenshot', 'Скриншот слишком маленький: нужно не меньше 320 пикселей по каждой стороне.');
        }

        $max = (int) config('storefront.catalog.screenshot_max_width');
        $targetWidth = min($width, $max);
        $targetHeight = (int) round($height * $targetWidth / $width);
        $resized = $this->resize($image, $targetWidth, $targetHeight, keepAlpha: false);

        return $this->write($resized, "catalog/{$appPublicId}/screenshot-".Str::lower(Str::random(12)).'.jpg', 'jpeg');
    }

    /**
     * The Home banner picture: landscape, stored as a WebP no wider than banner_max_width. The app crops
     * it to its card (about 16:10 on Home, 2:1 on the app page), so keep the subject centred.
     *
     * @return array{path: string, width: int, height: int}
     */
    public function storeBanner(UploadedFile $file, string $appPublicId): array
    {
        $image = $this->load($file);
        [$width, $height] = [imagesx($image), imagesy($image)];

        if ($width < 1000 || $width < $height * 1.3) {
            throw $this->invalid('banner', 'Баннер должен быть горизонтальным (шире высоты хотя бы в 1,3 раза) и не уже 1000 пикселей. Рекомендуем 1600×1000.');
        }

        return $this->bannerFrom($image, $appPublicId);
    }

    /**
     * Re-encodes an icon already on the public disk (the old 512 px PNGs) as the small WebP.
     * Returns null when the file is missing or unreadable.
     *
     * @return array{path: string, width: int, height: int, bytes_before: int, bytes_after: int}|null
     */
    public function optimizeStoredIcon(string $path, string $appPublicId): ?array
    {
        return $this->optimizeStored($path, fn (GdImage $image) => $this->iconFrom($image, $appPublicId));
    }

    /**
     * @return array{path: string, width: int, height: int, bytes_before: int, bytes_after: int}|null
     */
    public function optimizeStoredBanner(string $path, string $appPublicId): ?array
    {
        return $this->optimizeStored($path, fn (GdImage $image) => $this->bannerFrom($image, $appPublicId));
    }

    /**
     * @param  callable(GdImage): array{path: string, width: int, height: int}  $store
     * @return array{path: string, width: int, height: int, bytes_before: int, bytes_after: int}|null
     */
    private function optimizeStored(string $path, callable $store): ?array
    {
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }
        $bytes = (string) $disk->get($path);
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            return null;
        }

        $stored = $store($image);

        return $stored + ['bytes_before' => strlen($bytes), 'bytes_after' => $disk->size($stored['path'])];
    }

    /**
     * @return array{path: string, width: int, height: int}
     */
    private function iconFrom(GdImage $image, string $appPublicId): array
    {
        // Never scale an icon up: a small source stays as it is.
        $size = min((int) config('storefront.catalog.icon_size'), imagesx($image), imagesy($image));
        $resized = $this->resize($image, $size, $size, keepAlpha: true);

        return $this->write($resized, "catalog/{$appPublicId}/icon-".Str::lower(Str::random(8)).'.webp', 'webp', (int) config('storefront.catalog.icon_quality'));
    }

    /**
     * @return array{path: string, width: int, height: int}
     */
    private function bannerFrom(GdImage $image, string $appPublicId): array
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        $targetWidth = min($width, (int) config('storefront.catalog.banner_max_width'));
        $resized = $this->resize($image, $targetWidth, (int) round($height * $targetWidth / $width), keepAlpha: false);

        return $this->write($resized, "catalog/{$appPublicId}/banner-".Str::lower(Str::random(8)).'.webp', 'webp', (int) config('storefront.catalog.banner_quality'));
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    private function load(UploadedFile $file): GdImage
    {
        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if (! $image instanceof GdImage) {
            throw $this->invalid('image', 'Не удалось прочитать изображение. Подходят PNG, JPEG и WebP.');
        }

        return $image;
    }

    private function resize(GdImage $source, int $width, int $height, bool $keepAlpha): GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        if ($keepAlpha) {
            imagealphablending($target, false);
            imagesavealpha($target, true);
        } else {
            imagefill($target, 0, 0, (int) imagecolorallocate($target, 255, 255, 255));
        }
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $target;
    }

    /**
     * @return array{path: string, width: int, height: int}
     */
    private function write(GdImage $image, string $path, string $format, int $quality = 85): array
    {
        ob_start();
        match ($format) {
            'webp' => imagewebp($image, null, $quality),
            'png' => imagepng($image, null, 6),
            default => imagejpeg($image, null, $quality),
        };
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return ['path' => $path, 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    private function invalid(string $field, string $message): ApiException
    {
        return new ApiException(ErrorCode::ValidationFailed, $message, ['fields' => [$field => [$message]]]);
    }
}
