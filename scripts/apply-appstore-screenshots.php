<?php

declare(strict_types=1);

// Adds App Store screenshots to listings that have fewer than two.
// Run on the server as the storefront user:
//
//   sudo -u storefront php scripts/apply-appstore-screenshots.php /path/to/manifest.json
//
// manifest.json: {"apps": [{"public_id", "source", "urls": ["https://…mzstatic.com/…"]}]}
// Build it from the iTunes Lookup API by the app's bundle ID: an exact bundle match is the
// same app. Matching by name finds unrelated apps and must be checked by hand first.

use App\Models\CatalogApp;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\AppStoreImporter;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifestPath = $argv[1] ?? '';
if (! is_file($manifestPath)) {
    fwrite(STDERR, "Usage: php scripts/apply-appstore-screenshots.php /path/to/manifest.json\n");
    exit(2);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$importer = $app->make(AppStoreImporter::class);
$audit = $app->make(AuditService::class);
$updated = 0;
$short = 0;

foreach ($manifest['apps'] as $entry) {
    $catalog = CatalogApp::where('public_id', $entry['public_id'])->first();
    if ($catalog === null) {
        fwrite(STDERR, "MISSING {$entry['public_id']}\n");

        continue;
    }
    if ($catalog->screenshots()->count() >= 2) {
        echo "SKIP HAS-SCREENSHOTS {$catalog->name}\n";

        continue;
    }

    $added = $importer->addScreenshots($catalog, $entry['urls']);
    $audit->record('app.screenshots_imported', $catalog, after: ['count' => $added, 'source' => $entry['source']], actor: Actor::system('appstore-screenshots'));
    $total = $catalog->screenshots()->count();
    $total >= 2 ? $updated++ : $short++;
    echo ($total >= 2 ? 'OK' : 'SHORT')." {$catalog->name} +{$added} = {$total}\n";
}

echo "DONE updated={$updated} short={$short}\n";
