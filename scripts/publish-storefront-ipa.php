<?php

// Publishes a new build of the Ru App Store app itself (OWN_BUILD) to its hidden listing:
// stores the IPA, inspects it, approves and publishes it, like an operator in the admin.
// Run on the server as the app user:
//
//   sudo -u storefront php scripts/publish-storefront-ipa.php /path/to/RuAppStore.ipa
//
// The operator is whoever uploaded the build published now, so the audit trail stays theirs.

use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\CatalogApp;
use App\Models\User;
use App\Services\Artifacts\ArtifactInspectionService;
use App\Services\Artifacts\ArtifactReviewService;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../backend/vendor/autoload.php';
$app = require __DIR__.'/../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$source = $argv[1] ?? '';
if (! is_file($source)) {
    fwrite(STDERR, "Usage: php scripts/publish-storefront-ipa.php /path/to/RuAppStore.ipa\n");
    exit(2);
}

$listingId = AppleTeam::query()->whereNotNull('storefront_app_id')->value('storefront_app_id');
$listing = CatalogApp::withTrashed()->findOrFail($listingId);
$current = $listing->publishedArtifact()->first();
$operator = User::query()->findOrFail($current?->uploaded_by ?? 1);

$sha = hash_file('sha256', $source);
if (AppArtifact::query()->where(['app_id' => $listing->id, 'sha256' => $sha])->exists()) {
    fwrite(STDERR, "This IPA is already uploaded to {$listing->name}.\n");
    exit(1);
}

$relative = 'originals/'.substr($sha, 0, 2).'/'.$sha.'.ipa';
Storage::disk('artifacts')->put($relative, fopen($source, 'rb'));

$artifact = DB::transaction(function () use ($listing, $sha, $source, $relative, $operator): AppArtifact {
    $artifact = AppArtifact::create([
        'app_id' => $listing->id,
        'sha256' => $sha,
        'size_bytes' => filesize($source),
        'storage_disk' => 'artifacts',
        'storage_path' => $relative,
        'original_filename' => basename($source),
        'source_type' => SourceType::OwnBuild,
        'uploaded_by' => $operator->id,
        'declaration_version' => '2026-09-draft',
        'declaration_accepted_at' => now(),
        'status' => ArtifactStatus::Uploaded,
    ]);
    app(AuditService::class)->record('artifact.uploaded', $artifact, after: ['sha256' => $sha, 'size_bytes' => filesize($source)], actor: Actor::user($operator));

    return $artifact;
});

echo 'Inspection: '.app(ArtifactInspectionService::class)->inspect($artifact)."\n";
$artifact->refresh();
if ($artifact->status !== ArtifactStatus::ProvenanceReview) {
    fwrite(STDERR, "Held after inspection: {$artifact->status->value} ".json_encode($artifact->inspection['failure'] ?? $artifact->inspection['compatibility_issues'] ?? null)."\n");
    exit(1);
}

$review = app(ArtifactReviewService::class);
$review->approve($artifact, $operator, 'Own build of the Ru App Store app.', [
    'source_verified' => true,
    'distribution_rights_confirmed' => true,
    'inspection_report_reviewed' => true,
], false);
$review->publish($artifact->refresh(), $operator);
$artifact->refresh();

echo "Published {$artifact->bundle_identifier} {$artifact->version} ({$artifact->build_number}) as {$artifact->public_id}\n";
