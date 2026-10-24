<?php

declare(strict_types=1);

// php apply-telegram-thumbnails.php selection.json thumbnail-directory [limit]

use App\Models\CatalogApp;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\CatalogImageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

$backend = '/var/www/storefront/app/backend';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifestPath = $argv[1] ?? '';
$iconDir = $argv[2] ?? '';
$limit = isset($argv[3]) ? (int) $argv[3] : PHP_INT_MAX;
if (! is_file($manifestPath) || ! is_dir($iconDir) || $limit < 1) {
    fwrite(STDERR, "Usage: php apply-telegram-thumbnails.php selection.json thumbnail-directory [limit]\n");
    exit(2);
}
$selected = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR)['selected'];
$images = $app->make(CatalogImageService::class);
$audit = $app->make(AuditService::class);
$updated = 0;
$skipped = 0;
$failed = 0;

foreach ($selected as $entry) {
    if ($updated >= $limit) {
        break;
    }
    $messageId = (int) $entry['message_id'];
    $slug = 'tg-'.substr(hash('sha256', (string) $entry['bundle_identifier']), 0, 20);
    $catalog = CatalogApp::where('slug', $slug)->first();
    if ($catalog === null || $catalog->icon_path !== null) {
        $skipped++;
        continue;
    }
    $source = rtrim($iconDir, '/').'/'.$messageId.'.jpg';
    if (! is_file($source)) {
        $source = rtrim($iconDir, '/').'/'.$messageId.'.png';
    }
    if (! is_file($source) || is_link($source)) {
        fwrite(STDERR, "MISSING {$messageId} {$slug}\n");
        $failed++;
        continue;
    }
    $temporary = tempnam(sys_get_temp_dir(), 'tg-icon-');
    $stored = null;
    try {
        if ($temporary === false) {
            throw new RuntimeException('Could not allocate temporary icon file');
        }
        $bytes = file_get_contents($source);
        if ($bytes === false || strlen($bytes) > 2 * 1024 * 1024) {
            throw new RuntimeException('Thumbnail is unreadable or too large');
        }
        $sourceImage = @imagecreatefromstring($bytes);
        if (! $sourceImage instanceof GdImage) {
            throw new RuntimeException('Thumbnail is not a valid image');
        }
        $width = imagesx($sourceImage);
        $height = imagesy($sourceImage);
        if ($width !== $height || $width < 120 || $width > 2048) {
            throw new RuntimeException("Thumbnail dimensions are invalid: {$width}x{$height}");
        }
        $normalised = imagecreatetruecolor(512, 512);
        imagecopyresampled($normalised, $sourceImage, 0, 0, 0, 0, 512, 512, $width, $height);
        imagepng($normalised, $temporary, 6);
        imagedestroy($normalised);
        imagedestroy($sourceImage);
        $stored = $images->storeIcon(new UploadedFile($temporary, 'telegram-thumbnail.png', 'image/png', null, true), $catalog->public_id);
        DB::transaction(function () use ($catalog, $stored, $entry, $source, $audit): void {
            $origin = str_ends_with($source, '.png') ? 'IPA_EMBEDDED_ICON' : 'TELEGRAM_DOCUMENT_THUMBNAIL';
            $catalog->forceFill(['icon_path' => $stored['path']])->save();
            $audit->record('app.icon_updated', $catalog, after: [
                'icon_path' => $stored['path'],
                'source_url' => $entry['url'],
                'icon_source' => $origin,
                'source_image_sha256' => hash_file('sha256', $source),
            ], reason: $origin === 'IPA_EMBEDDED_ICON' ? 'Icon extracted from the selected IPA.' : 'Icon derived from the Telegram IPA document thumbnail.', actor: Actor::system('telegram-icon-import'));
        });
        echo "UPDATED {$messageId} {$slug} {$stored['path']}\n";
        $updated++;
    } catch (Throwable $error) {
        if ($stored !== null) {
            $images->delete($stored['path']);
        }
        fwrite(STDERR, "FAILED {$messageId} {$slug}: ".get_class($error).' '.$error->getMessage()."\n");
        $failed++;
    } finally {
        if ($temporary !== false) {
            @unlink($temporary);
        }
    }
    fflush(STDOUT);
    fflush(STDERR);
}

echo "DONE updated={$updated} skipped={$skipped} failed={$failed}\n";
exit($failed === 0 ? 0 : 1);
