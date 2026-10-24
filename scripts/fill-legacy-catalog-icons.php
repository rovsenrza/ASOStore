<?php

declare(strict_types=1);

// Fill the two pre-existing, non-public catalog cards that have no icon.

use App\Models\CatalogApp;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\CatalogImageService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$backend = '/var/www/storefront/app/backend';
require $backend.'/vendor/autoload.php';
$laravel = require $backend.'/bootstrap/app.php';
$laravel->make(Kernel::class)->bootstrap();

$sourceApp = CatalogApp::where('slug', 'amneziavpn')->firstOrFail();
if ($sourceApp->icon_path === null) {
    throw new RuntimeException('The published AmneziaVPN app has no icon to copy.');
}
$sources = [
    'storefront' => '/var/www/storefront/app/ios/Storefront/Resources/Assets.xcassets/AppIcon.appiconset/AppIcon.png',
    'amnezia-vpn' => Storage::disk('public')->path($sourceApp->icon_path),
];
$images = $laravel->make(CatalogImageService::class);
$audit = $laravel->make(AuditService::class);

foreach ($sources as $slug => $source) {
    $catalog = CatalogApp::where('slug', $slug)->firstOrFail();
    if ($catalog->icon_path !== null) {
        echo "SKIP {$slug}\n";
        continue;
    }
    if (! is_file($source)) {
        throw new RuntimeException("Icon source is missing: {$source}");
    }
    $stored = $images->storeIcon(new UploadedFile($source, basename($source), 'image/png', null, true), $catalog->public_id);
    try {
        DB::transaction(function () use ($catalog, $stored, $slug, $source, $audit): void {
            $catalog->forceFill(['icon_path' => $stored['path']])->save();
            $audit->record('app.icon_updated', $catalog, after: [
                'icon_path' => $stored['path'],
                'source' => $slug === 'storefront' ? 'native_app_icon' : 'published_amneziavpn_icon',
                'source_image_sha256' => hash_file('sha256', $source),
            ], actor: Actor::system('catalog-icon-import'));
        });
    } catch (Throwable $error) {
        $images->delete($stored['path']);
        throw $error;
    }
    echo "UPDATED {$slug} {$stored['path']}\n";
}
