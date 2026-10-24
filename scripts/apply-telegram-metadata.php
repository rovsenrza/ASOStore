<?php

declare(strict_types=1);

// php apply-telegram-metadata.php /absolute/path/to/appstore-metadata.json

use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\CatalogImageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

$backend = '/var/www/storefront/app/backend';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$path = $argv[1] ?? '';
if (! is_file($path)) {
    fwrite(STDERR, "Usage: php apply-telegram-metadata.php /absolute/path/to/appstore-metadata.json\n");
    exit(2);
}
$cache = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$images = $app->make(CatalogImageService::class);
$audit = $app->make(AuditService::class);
$categories = [6000 => 'business', 6001 => 'weather', 6002 => 'utilities', 6003 => 'travel', 6005 => 'social',
    6007 => 'productivity', 6008 => 'photo-video', 6011 => 'music', 6013 => 'health', 6015 => 'finance',
    6016 => 'entertainment', 6017 => 'education'];
$gameCategories = [7001 => 'games-action', 7003 => 'games-arcade', 7012 => 'games-puzzle', 7013 => 'games-racing'];
$updated = 0;
$failures = 0;

foreach ($cache as $bundle => $listing) {
    if (! is_array($listing) || $listing === [] || ($listing['bundleId'] ?? null) !== $bundle) {
        continue;
    }
    $slug = 'tg-'.substr(hash('sha256', (string) $bundle), 0, 20);
    $catalog = CatalogApp::where('slug', $slug)->first();
    if ($catalog === null) {
        continue;
    }
    try {
        $changes = [];
        $name = trim((string) ($listing['trackName'] ?? ''));
        if ($name !== '') {
            $changes['name'] = mb_substr($name, 0, 100);
        }
        $description = trim((string) ($listing['description'] ?? ''));
        if ($description !== '') {
            $changes['description'] = mb_substr($description, 0, 4000);
        }
        $storeId = (string) ($listing['trackId'] ?? '');
        if ($storeId !== '' && ! CatalogApp::where('app_store_id', $storeId)->whereKeyNot($catalog->id)->exists()) {
            $changes['app_store_id'] = $storeId;
        }
        $seller = trim((string) ($listing['sellerName'] ?? $listing['artistName'] ?? ''));
        if ($seller !== '') {
            $changes['publisher_id'] = AppPublisher::firstOrCreate(['name' => mb_substr($seller, 0, 255)])->id;
        }
        $genre = (int) ($listing['primaryGenreId'] ?? 0);
        $categorySlug = $categories[$genre] ?? null;
        if ($genre === 6014) {
            foreach ((array) ($listing['genreIds'] ?? []) as $gameGenre) {
                if (isset($gameCategories[(int) $gameGenre])) {
                    $categorySlug = $gameCategories[(int) $gameGenre];
                    break;
                }
            }
        }
        if ($categorySlug !== null && ($category = AppCategory::where('slug', $categorySlug)->first()) !== null) {
            $changes['category_id'] = $category->id;
        }
        $years = (int) ($listing['contentAdvisoryRating'] ?? 0);
        $changes['age_rating'] = $years >= 17 ? '17+' : ($years >= 12 ? '12+' : ($years >= 9 ? '9+' : '4+'));
        if ($changes !== []) {
            $catalog->fill($changes)->save();
            $audit->record('app.metadata_enriched', $catalog, after: ['app_store_id' => $storeId, 'original_bundle_identifier' => $bundle], actor: Actor::system('telegram-import'));
        }
        $iconUrl = (string) ($listing['artworkUrl512'] ?? $listing['artworkUrl100'] ?? '');
        $host = (string) parse_url($iconUrl, PHP_URL_HOST);
        if ($catalog->icon_path === null && str_starts_with($iconUrl, 'https://') && str_ends_with($host, '.mzstatic.com')) {
            $iconUrl = (string) preg_replace('#/[^/]+$#', '/1024x1024bb.png', $iconUrl);
            $response = Http::timeout(30)->get($iconUrl)->throw();
            $temporary = tempnam(sys_get_temp_dir(), 'tg-icon-');
            if ($temporary === false) {
                throw new RuntimeException('Could not allocate icon file');
            }
            try {
                file_put_contents($temporary, $response->body());
                $icon = $images->storeIcon(new UploadedFile($temporary, 'icon.png', null, null, true), $catalog->public_id);
                $catalog->forceFill(['icon_path' => $icon['path']])->save();
            } finally {
                @unlink($temporary);
            }
        }
        echo "UPDATED {$bundle} {$catalog->public_id}\n";
        $updated++;
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAILED {$bundle}: ".get_class($error).' '.$error->getMessage()."\n");
    }
    fflush(STDOUT);
    fflush(STDERR);
}

echo "DONE updated={$updated} failures={$failures}\n";
exit($failures === 0 ? 0 : 1);
