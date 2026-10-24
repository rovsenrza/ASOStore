<?php

/**
 * Register a local IPA as a READY (non-published) AppArtifact for an existing listing,
 * so it can be signed for a test device with `php artisan catalog:device-test sign <id> --device N`.
 *
 * It drives the ordinary services (inspection -> review/approve), so every state change is
 * audited exactly like a normal upload. It NEVER publishes: the live build is untouched until
 * you run the publish swap yourself after the device test passes.
 *
 * Usage (on the server, from the app root):
 *   php artisan tinker --execute="require 'scripts/prepare-test-artifact.php';" ARGS...
 * or, simpler, run it directly through artisan's bootstrapped container:
 *   APP_ID=67 IPA=/tmp/patrolkit.ipa REVIEWER=admin@example.com php scripts/prepare-test-artifact.php
 *
 * Env:
 *   APP_ID    target listing id (required), e.g. 67 for «Авито»
 *   IPA       absolute path to the IPA on the server (required)
 *   REVIEWER  email (or id) of the admin user recorded as uploader/reviewer (required)
 */

use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\AppArtifact;
use App\Models\CatalogApp;
use App\Models\User;
use App\Services\Artifacts\ArtifactReviewService;
use App\Services\Inspection\IpaInspector;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\Storage;

// --- bootstrap Laravel if invoked as a plain script -------------------------------------------
if (! function_exists('app')) {
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
}

$appId    = (int) (getenv('APP_ID') ?: 0);
$ipaPath  = (string) (getenv('IPA') ?: '');
$reviewer = (string) (getenv('REVIEWER') ?: '');

if ($appId <= 0 || $ipaPath === '' || $reviewer === '') {
    fwrite(STDERR, "APP_ID, IPA and REVIEWER are all required.\n");
    exit(2);
}
if (! is_file($ipaPath)) {
    fwrite(STDERR, "IPA not found: {$ipaPath}\n");
    exit(2);
}

$app = CatalogApp::query()->findOrFail($appId);
$user = ctype_digit($reviewer)
    ? User::query()->findOrFail((int) $reviewer)
    : User::query()->where('email', $reviewer)->firstOrFail();

$sha  = hash_file('sha256', $ipaPath);
$size = filesize($ipaPath);

echo "Listing {$app->id}  «{$app->name}»  source={$app->source_type->value}\n";
echo "IPA {$ipaPath}\n  sha256={$sha}\n  size={$size}\n";

// Reuse an existing READY artifact for the same bytes rather than making a duplicate.
$existing = AppArtifact::query()
    ->where('app_id', $app->id)->where('sha256', $sha)
    ->where('status', ArtifactStatus::Ready->value)->orderByDesc('id')->first();
if ($existing !== null) {
    echo "Already READY as artifact {$existing->id} ({$existing->public_id}). Nothing to do.\n";
    echo "NEXT: php artisan catalog:device-test sign {$existing->id} --device 1\n";
    return;
}

// 1) Store the IPA on the artifacts disk.
$storagePath = 'artifacts/'.$sha.'.ipa';
if (! Storage::disk('artifacts')->exists($storagePath)) {
    echo "Uploading to artifacts disk at {$storagePath} ...\n";
    $stream = fopen($ipaPath, 'rb');
    Storage::disk('artifacts')->writeStream($storagePath, $stream);
    fclose($stream);
}

/** @var StateMachine $states */
$states = app(StateMachine::class);
/** @var IpaInspector $inspector */
$inspector = app(IpaInspector::class);
/** @var ArtifactReviewService $review */
$review = app(ArtifactReviewService::class);

// 2) Create the artifact and walk it through inspection like any upload.
$artifact = AppArtifact::create([
    'app_id'                  => $app->id,
    'sha256'                  => $sha,
    'size_bytes'              => $size,
    'storage_disk'            => 'artifacts',
    'storage_path'            => $storagePath,
    'original_filename'       => basename($ipaPath),
    'source_type'             => SourceType::CustomerProvided,
    'uploaded_by'             => $user->id,
    'declaration_version'     => (string) (config('storefront.quick_publish.declaration_version') ?: '1'),
    'declaration_accepted_at' => now(),
    'declaration_ip'          => '127.0.0.1',
    'status'                  => ArtifactStatus::Uploaded,
]);
echo "Created artifact {$artifact->id} ({$artifact->public_id}) UPLOADED\n";

$states->transition($artifact, ArtifactStatus::Hashing);
$states->transition($artifact, ArtifactStatus::Inspecting);

echo "Inspecting ...\n";
$result = $inspector->inspect($ipaPath);
$bundle = $result->report['bundle'] ?? [];
$artifact->forceFill([
    'bundle_identifier' => $bundle['bundle_identifier'] ?? null,
    'version'           => $bundle['version'] ?? null,
    'build_number'      => $bundle['build_number'] ?? null,
    'min_ios_version'   => $bundle['min_ios_version'] ?? null,
    'inspection'        => $result->report,
])->save();
echo "  bundle={$artifact->bundle_identifier}  v{$artifact->version} ({$artifact->build_number})  minOS={$artifact->min_ios_version}\n";

if (! $result->passed()) {
    $states->transition($artifact, $result->outcome, 'Inspection failed', extra: ['status_reason' => $result->failureCode]);
    fwrite(STDERR, "Inspection did NOT pass: {$result->outcome->value} ({$result->failureCode}). Artifact {$artifact->id} left for review.\n");
    exit(1);
}
$states->transition($artifact, ArtifactStatus::ProvenanceReview);

// 3) Approve to READY (no publish). Acknowledge the scan explicitly: this is a trusted self-upload.
$review->approve(
    $artifact,
    $user,
    'Prepared for on-device demask test (not published).',
    ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true],
    scanAcknowledged: true,
);
$artifact->refresh();
echo "Artifact {$artifact->id} is now {$artifact->status->value}\n";

if ($artifact->status === ArtifactStatus::Ready) {
    echo "NEXT: php artisan catalog:device-test sign {$artifact->id} --device 1\n";
} else {
    fwrite(STDERR, "Expected READY, got {$artifact->status->value}.\n");
    exit(1);
}
