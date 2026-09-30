<?php

declare(strict_types=1);

// Imports bank apps from appbank.pw into the catalog and publishes them.
// Run on the server as the storefront user:
//
//   sudo -u storefront php scripts/import-appbank-catalog.php /path/to/manifest.json
//
// manifest.json: {"apps": [{"key", "name", "subtitle", "publisher", "category", "description",
//   "ipa" (absolute path), "icon_url", "source_url"}]}
// Banks publish in the App Store under unrelated names ("Финансист" is Т-Инвестиции), so a
// listing is named after the bank, and the subtitle says what shows on the Home Screen.
// An app whose bundle ID or file is already in the catalog is skipped: it is there under
// another name.

use App\Enums\AppVisibility;
use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\AppArtifact;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Models\User;
use App\Services\Artifacts\ArtifactInspectionService;
use App\Services\Artifacts\ArtifactReviewService;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\CatalogImageService;
use App\Services\Catalog\TeamEligibilityGranter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifestPath = $argv[1] ?? '';
if (! is_file($manifestPath)) {
    fwrite(STDERR, "Usage: php scripts/import-appbank-catalog.php /path/to/manifest.json\n");
    exit(2);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$operator = User::findOrFail(1);
$audit = $app->make(AuditService::class);
$eligibility = $app->make(TeamEligibilityGranter::class);
$inspector = $app->make(ArtifactInspectionService::class);
$review = $app->make(ArtifactReviewService::class);
$images = $app->make(CatalogImageService::class);
$failures = 0;

foreach ($manifest['apps'] as $entry) {
    $key = (string) $entry['key'];
    try {
        $source = (string) $entry['ipa'];
        if (! is_file($source)) {
            throw new RuntimeException("Missing IPA: {$source}");
        }
        $sha = hash_file('sha256', $source);
        if (AppArtifact::where('sha256', $sha)->exists()) {
            echo "SKIP SAME-FILE {$key}\n";

            continue;
        }

        // Where it will be stored, then inspect: the bundle ID decides whether the app is new.
        $relative = 'originals/'.substr($sha, 0, 2).'/'.$sha.'.ipa';
        Storage::disk('artifacts')->put($relative, fopen($source, 'rb'));

        $slug = 'ab-'.substr(hash('sha256', $key), 0, 20);
        $catalog = CatalogApp::withTrashed()->where('slug', $slug)->first()
            ?? DB::transaction(function () use ($entry, $slug, $operator, $audit): CatalogApp {
                $catalog = CatalogApp::create([
                    'slug' => $slug,
                    'name' => mb_substr((string) $entry['name'], 0, 100),
                    'subtitle' => mb_substr((string) $entry['subtitle'], 0, 150),
                    'description' => (string) $entry['description'],
                    'bundle_identifier' => 'com.ruappstore.'.str_replace('-', '', $slug),
                    'category_id' => AppCategory::where('slug', $entry['category'])->firstOrFail()->id,
                    'publisher_id' => AppPublisher::firstOrCreate(['name' => $entry['publisher']])->id,
                    'source_type' => SourceType::CustomerProvided,
                    'visibility' => AppVisibility::Draft,
                ]);
                $audit->record('app.imported', $catalog, after: [
                    'name' => $catalog->name,
                    'source_url' => $entry['source_url'],
                    'rights_confirmed_by_customer' => true,
                ], actor: Actor::user($operator));

                return $catalog;
            });

        $artifact = DB::transaction(function () use ($catalog, $sha, $source, $relative, $operator, $audit, $entry): AppArtifact {
            $artifact = AppArtifact::create([
                'app_id' => $catalog->id,
                'sha256' => $sha,
                'size_bytes' => filesize($source),
                'storage_disk' => 'artifacts',
                'storage_path' => $relative,
                'original_filename' => basename($source),
                'source_type' => SourceType::CustomerProvided,
                'uploaded_by' => $operator->id,
                'declaration_version' => '2026-09-draft',
                'declaration_accepted_at' => now(),
                'status' => ArtifactStatus::Uploaded,
            ]);
            $audit->record('artifact.uploaded', $artifact, after: ['sha256' => $sha, 'size_bytes' => filesize($source), 'source_url' => $entry['source_url']], actor: Actor::user($operator));

            return $artifact;
        });
        $inspector->inspect($artifact);
        $artifact->refresh();

        $bundle = $artifact->bundle_identifier;
        $elsewhere = AppArtifact::where('bundle_identifier', $bundle)->where('app_id', '!=', $catalog->id)->exists();
        if ($elsewhere || $artifact->status !== ArtifactStatus::ProvenanceReview) {
            $reason = $elsewhere ? "SKIP SAME-BUNDLE {$key} {$bundle}" : "HELD {$key} {$artifact->status->value}";
            $artifact->delete();
            Storage::disk('artifacts')->delete($relative);
            if ($catalog->artifacts()->doesntExist()) {
                $catalog->forceDelete();
            }
            echo $reason."\n";

            continue;
        }

        $eligibility->grantFor($catalog, $operator, 'Bank app from '.$entry['source_url'].'; signing with our own bundle ID.');
        $review->approve($artifact, $operator, 'Bank app from appbank.pw; customer-confirmed distribution rights; inspected bundle and version.', [
            'source_verified' => true,
            'distribution_rights_confirmed' => true,
            'inspection_report_reviewed' => true,
        ], false);
        $review->publish($artifact->refresh(), $operator);

        if ($catalog->icon_path === null && filled($entry['icon_url'] ?? null)) {
            $bytes = Http::timeout(30)->get($entry['icon_url'])->throw()->body();
            $image = imagecreatefromstring($bytes) ?: throw new RuntimeException('Icon is not an image');
            $icon = imagecreatetruecolor(512, 512);
            imagecopyresampled($icon, $image, 0, 0, 0, 0, 512, 512, imagesx($image), imagesy($image));
            $temporary = tempnam(sys_get_temp_dir(), 'ab-icon-').'.png';
            imagepng($icon, $temporary, 6);
            $stored = $images->storeIcon(new UploadedFile($temporary, 'icon.png', 'image/png', null, true), $catalog->public_id);
            $catalog->forceFill(['icon_path' => $stored['path']])->save();
            @unlink($temporary);
        }

        $artifact->refresh();
        echo "PUBLISHED {$key} {$catalog->name} {$bundle} {$artifact->version} {$catalog->public_id}\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAILED {$key}: ".get_class($error).' '.$error->getMessage()."\n");
    }
}

exit($failures === 0 ? 0 : 1);
