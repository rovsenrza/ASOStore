<?php

declare(strict_types=1);

// Run on the storefront server as the storefront user:
// php import-telegram-catalog.php /absolute/path/to/selection.json [limit]

use App\Enums\AppVisibility;
use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\AppArtifact;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Models\User;
use App\Services\Artifacts\ArtifactInspectionService;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\TeamEligibilityGranter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$backend = '/var/www/storefront/app/backend';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifestPath = $argv[1] ?? '';
$limit = isset($argv[2]) ? (int) $argv[2] : PHP_INT_MAX;
if (! is_file($manifestPath) || $limit < 1) {
    fwrite(STDERR, "Usage: php import-telegram-catalog.php /absolute/path/to/selection.json [limit]\n");
    exit(2);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$root = dirname(dirname(dirname($manifestPath)));
$operator = User::findOrFail(1);
$category = AppCategory::where('slug', 'utilities')->firstOrFail();
$publisher = AppPublisher::firstOrCreate(['name' => 'Разработчик не указан']);
$audit = $app->make(AuditService::class);
$eligibility = $app->make(TeamEligibilityGranter::class);
$inspector = $app->make(ArtifactInspectionService::class);
$count = 0;
$failures = 0;

foreach ($manifest['selected'] as $entry) {
    if ($count >= $limit) {
        break;
    }
    $bundle = (string) $entry['bundle_identifier'];
    $version = (string) $entry['version'];
    $build = (string) $entry['build_number'];
    $message = (int) $entry['message_id'];
    if (stripos((string) $entry['filename'], 'com.diyorde') !== false || stripos($bundle, 'com.diyorde') !== false) {
        echo "SKIP DIYORDE {$message}\n";
        continue;
    }
    try {
        $existing = AppArtifact::where('bundle_identifier', $bundle)
            ->where('version', $version)->where('build_number', $build)
            ->whereNotIn('status', ['REJECTED', 'INSPECTION_FAILED', 'PROVENANCE_FAILED'])
            ->first();
        if ($existing !== null) {
            echo "SKIP EXISTING {$message} {$bundle} {$version}\n";
            continue;
        }
        $source = $root.'/'.ltrim((string) $entry['path'], '/');
        if (! is_file($source) || filesize($source) !== (int) $entry['size_bytes']) {
            throw new RuntimeException("Missing or wrong-sized source file: {$source}");
        }
        $sha = hash_file('sha256', $source);
        if ($sha === false) {
            throw new RuntimeException('Could not hash source file');
        }
        if (AppArtifact::where('sha256', $sha)->exists()) {
            echo "SKIP SHA {$message} {$bundle}\n";
            continue;
        }

        $key = substr(hash('sha256', $bundle), 0, 20);
        $slug = 'tg-'.$key;
        $catalog = CatalogApp::withTrashed()->where('slug', $slug)->first();
        if ($catalog !== null && $catalog->trashed()) {
            throw new RuntimeException("Listing {$slug} has been deleted");
        }
        if ($catalog === null) {
            $catalog = DB::transaction(function () use ($entry, $bundle, $slug, $key, $category, $publisher, $operator, $audit, $eligibility): CatalogApp {
                $name = trim((string) $entry['name']);
                $catalog = CatalogApp::create([
                    'slug' => $slug,
                    'name' => mb_substr($name ?: $bundle, 0, 100),
                    'bundle_identifier' => 'com.ruappstore.tg'.$key,
                    'category_id' => $category->id,
                    'publisher_id' => $publisher->id,
                    'source_type' => SourceType::CustomerProvided,
                    'visibility' => AppVisibility::Draft,
                ]);
                $audit->record('app.imported', $catalog, after: [
                    'name' => $catalog->name,
                    'source_url' => $entry['url'],
                    'original_bundle_identifier' => $bundle,
                    'rights_confirmed_by_customer' => true,
                ], actor: Actor::user($operator));
                $eligibility->grantFor($catalog, $operator, 'Customer-provided IPA from '.$entry['url'].'; signing with our own bundle ID.');

                return $catalog;
            });
        }

        $relative = 'originals/'.substr($sha, 0, 2).'/'.$sha.'.ipa';
        // Works for the local disk and for object storage (ARTIFACTS_DRIVER=s3).
        $stream = fopen($source, 'rb');
        if ($stream === false || ! Storage::disk('artifacts')->writeStream($relative, $stream)) {
            throw new RuntimeException('Could not move source file into artifact storage');
        }
        fclose($stream);
        unlink($source);
        $artifact = DB::transaction(function () use ($catalog, $sha, $entry, $relative, $operator, $audit): AppArtifact {
            $artifact = AppArtifact::create([
                'app_id' => $catalog->id,
                'sha256' => $sha,
                'size_bytes' => $entry['size_bytes'],
                'storage_disk' => 'artifacts',
                'storage_path' => $relative,
                'original_filename' => mb_substr((string) $entry['filename'], 0, 255),
                'source_type' => SourceType::CustomerProvided,
                'uploaded_by' => $operator->id,
                'declaration_version' => '2026-09-draft',
                'declaration_accepted_at' => now(),
                'status' => ArtifactStatus::Uploaded,
            ]);
            $audit->record('artifact.uploaded', $artifact, after: [
                'sha256' => $sha,
                'size_bytes' => $entry['size_bytes'],
                'source_url' => $entry['url'],
                'rights_confirmed_by_customer' => true,
            ], actor: Actor::user($operator));

            return $artifact;
        });
        $result = $inspector->inspect($artifact);
        $artifact->refresh();
        echo "IMPORTED {$message} {$bundle} {$version} {$artifact->public_id} {$artifact->status->value} {$result}\n";
        $count++;
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAILED {$message} {$bundle}: ".get_class($error).' '.$error->getMessage()."\n");
    }
    fflush(STDOUT);
    fflush(STDERR);
}

echo "DONE imported={$count} failures={$failures}\n";
exit($failures === 0 ? 0 : 1);
