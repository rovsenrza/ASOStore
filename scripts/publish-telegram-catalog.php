<?php

declare(strict_types=1);

// Run on the storefront server as the storefront user after import and inspection:
// php publish-telegram-catalog.php /absolute/path/to/selection.json [limit]

use App\Enums\ArtifactStatus;
use App\Models\AppArtifact;
use App\Models\User;
use App\Services\Artifacts\ArtifactReviewService;
use Illuminate\Contracts\Console\Kernel;

$backend = '/var/www/storefront/app/backend';
require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifestPath = $argv[1] ?? '';
$limit = isset($argv[2]) ? (int) $argv[2] : PHP_INT_MAX;
if (! is_file($manifestPath) || $limit < 1) {
    fwrite(STDERR, "Usage: php publish-telegram-catalog.php /absolute/path/to/selection.json [limit]\n");
    exit(2);
}
$manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
$operator = User::findOrFail(1);
$review = $app->make(ArtifactReviewService::class);
$published = 0;
$held = 0;
$failures = 0;

foreach ($manifest['selected'] as $entry) {
    if ($published >= $limit) {
        break;
    }
    $message = (int) $entry['message_id'];
    $bundle = (string) $entry['bundle_identifier'];
    $artifact = AppArtifact::where('bundle_identifier', $bundle)
        ->where('version', $entry['version'])
        ->where('build_number', $entry['build_number'])
        ->latest('id')->first();
    if ($artifact === null) {
        echo "HELD MISSING {$message} {$bundle}\n";
        $held++;
        continue;
    }
    if ($artifact->status === ArtifactStatus::Published) {
        echo "SKIP PUBLISHED {$message} {$bundle}\n";
        continue;
    }
    if ($artifact->status !== ArtifactStatus::ProvenanceReview) {
        echo "HELD STATUS {$message} {$bundle} {$artifact->status->value}\n";
        $held++;
        continue;
    }
    $inspection = $artifact->inspection ?? [];
    if (($inspection['malware_scan']['status'] ?? null) !== 'CLEAN'
        || ! empty($inspection['compatibility_issues'])
        || ! empty($inspection['failure'])
        || ($inspection['bundle']['bundle_identifier'] ?? null) !== $bundle
        || ($inspection['bundle']['version'] ?? null) !== $entry['version']) {
        echo "HELD INSPECTION {$message} {$bundle}\n";
        $held++;
        continue;
    }
    try {
        $reason = 'Customer-confirmed distribution rights for the selected 238 Telegram IPAs; source '.$entry['url'].'; clean malware scan and inspected bundle/version.';
        $review->approve($artifact, $operator, $reason, [
            'source_verified' => true,
            'distribution_rights_confirmed' => true,
            'inspection_report_reviewed' => true,
        ], false);
        $artifact->refresh();
        if ($artifact->status !== ArtifactStatus::Ready) {
            echo "HELD COMPATIBILITY {$message} {$bundle} {$artifact->status->value}\n";
            $held++;
            continue;
        }
        $review->publish($artifact, $operator);
        echo "PUBLISHED {$message} {$bundle} {$artifact->public_id}\n";
        $published++;
    } catch (Throwable $error) {
        fwrite(STDERR, "FAILED {$message} {$bundle}: ".get_class($error).' '.$error->getMessage()."\n");
        $failures++;
    }
    fflush(STDOUT);
    fflush(STDERR);
}

echo "DONE published={$published} held={$held} failures={$failures}\n";
exit($failures === 0 ? 0 : 1);
