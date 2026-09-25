<?php

namespace App\Services\Catalog;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Normalises catalog media with GD: icons become square PNGs, screenshots
 * JPEGs no wider than the configured maximum. Re-encoding also drops any
 * metadata (EXIF, location) the original file carried.
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

        $size = (int) config('storefront.catalog.icon_size');
        $resized = $this->resize($image, $size, $size, keepAlpha: true);

        return $this->write($resized, "catalog/{$appPublicId}/icon-".Str::lower(Str::random(8)).'.png', 'png');
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
    private function write(GdImage $image, string $path, string $format): array
    {
        ob_start();
        $format === 'png' ? imagepng($image, null, 6) : imagejpeg($image, null, 85);
        Storage::disk('public')->put($path, (string) ob_get_clean());

        return ['path' => $path, 'width' => imagesx($image), 'height' => imagesy($image)];
    }

    private function invalid(string $field, string $message): ApiException
    {
        return new ApiException(ErrorCode::ValidationFailed, $message, ['fields' => [$field => [$message]]]);
    }
}
