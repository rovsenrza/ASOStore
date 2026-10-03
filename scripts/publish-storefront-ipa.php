<?php

// Publishes a new build of the Ru App Store app itself (OWN_BUILD) to its hidden listing:
// stores the IPA, inspects it, approves and publishes it, like an operator in the admin.
// Run on the server as the app user:
//
//   sudo -u storefront php scripts/publish-storefront-ipa.php /path/to/RuAppStore.ipa
//
// Each Apple team has its own variant with its own bundle ID (ADR 0001). For a team other than
// the first, name it and the bundle ID the IPA was built with:
//
//   sudo -u storefront php scripts/publish-storefront-ipa.php RuAppStore-app2.ipa --team=WU5Y6G68J9 --bundle=com.ruappstore.app2
//
// The first time, this creates the team's hidden Ru App Store listing (copied from the first
// team's), links it to the team and approves the bundle ID for that team.
//
// The operator is whoever uploaded the build published now, so the audit trail stays theirs.

use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\AppArtifact;
use App\Models\AppleTeam;
use App\Models\CatalogApp;
use App\Models\TeamAppEligibility;
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

// Options may come before or after the file (getopt stops at the first plain argument).
$options = [];
$source = '';
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--(team|bundle)=(.+)$/', $argument, $match) === 1) {
        $options[$match[1]] = $match[2];
    } elseif (! str_starts_with($argument, '--')) {
        $source = $argument;
    }
}
if (! is_file($source)) {
    fwrite(STDERR, "Usage: php scripts/publish-storefront-ipa.php /path/to/RuAppStore.ipa [--team=TEAMID --bundle=com.ruappstore.appN]\n");
    exit(2);
}

$firstListingId = AppleTeam::query()->whereNotNull('storefront_app_id')->orderBy('id')->value('storefront_app_id');
$firstListing = CatalogApp::withTrashed()->findOrFail($firstListingId);
$expectedBundle = $options['bundle'] ?? null;

if (isset($options['team'])) {
    $team = AppleTeam::query()->where('apple_team_id', strtoupper($options['team']))->firstOrFail();
    if ($expectedBundle === null) {
        fwrite(STDERR, "--team needs --bundle (the variant's own bundle ID).\n");
        exit(2);
    }
    $listing = $team->storefrontApp;
    if ($listing === null) {
        $listing = $firstListing->replicate(['public_id', 'slug', 'featured_rank']);
        $listing->slug = $firstListing->slug.'-'.strtolower($team->apple_team_id);
        $listing->bundle_identifier = null;
        $listing->save();
        $team->forceFill(['storefront_app_id' => $listing->id])->save();
        app(AuditService::class)->record('apple_team.updated', $team, after: ['storefront_app_id' => $listing->public_id], reason: 'Ru App Store variant for this team.', actor: Actor::system('cli'));
        echo "Created listing {$listing->public_id} for team {$team->apple_team_id}\n";
    }
    $operator = User::query()->findOrFail($firstListing->publishedArtifact()->value('uploaded_by') ?? 1);
    // The variant's bundle ID belongs to this team alone.
    TeamAppEligibility::query()->updateOrCreate(
        ['apple_team_id' => $team->id, 'bundle_identifier' => $expectedBundle],
        ['evidence' => 'Ru App Store variant of this team.', 'status' => 'APPROVED', 'approved_by' => $operator->id, 'approved_at' => now()],
    );
} else {
    $listing = $firstListing;
    $operator = User::query()->findOrFail($listing->publishedArtifact()->value('uploaded_by') ?? 1);
}

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

if ($expectedBundle !== null && $artifact->bundle_identifier !== $expectedBundle) {
    fwrite(STDERR, "The IPA's bundle ID is {$artifact->bundle_identifier}, not {$expectedBundle}; not published.\n");
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
