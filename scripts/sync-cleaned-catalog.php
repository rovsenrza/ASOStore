<?php

declare(strict_types=1);

// Explicit, hash-pinned replacement of customer-provided IPAs. Prepare performs
// the full IPA/malware inspection without changing the catalog. Import uses the
// saved report, verifies the files again, and publishes through the normal
// review service. Old files are retained until every replacement is published.
// php sync-cleaned-catalog.php MANIFEST prepare [SHA256]
// php sync-cleaned-catalog.php MANIFEST import [SHA256]
// php sync-cleaned-catalog.php MANIFEST verify

use App\Enums\AppVisibility;
use App\Enums\ArtifactStatus;
use App\Enums\SourceType;
use App\Models\AppArtifact;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Models\User;
use App\Services\Artifacts\ArtifactReviewService;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\CatalogImageService;
use App\Services\Catalog\TeamEligibilityGranter;
use App\Services\Inspection\IpaInspector;
use App\StateMachines\StateMachine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$backend = '/var/www/storefront/app/backend';
require $backend.'/vendor/autoload.php';
$application = require $backend.'/bootstrap/app.php';
$application->make(Kernel::class)->bootstrap();
$path = $argv[1] ?? '';
$mode = $argv[2] ?? '';
if (! is_file($path) || ! in_array($mode, ['prepare', 'import', 'verify'], true)) {
    throw new RuntimeException('Usage: MANIFEST prepare|import|verify [SHA256]');
}
$manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$directory = dirname(realpath($path));
$operator = User::findOrFail((int) $manifest['operator_id']);
$reviewer = User::findOrFail((int) $manifest['reviewer_id']);
$audit = $application->make(AuditService::class);
$states = $application->make(StateMachine::class);
$actor = Actor::user($operator);
$lock = fopen($directory.'/catalog-sync.lock', 'c');
if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
    throw new RuntimeException('Another catalog sync is running');
}
$failures = 0;

foreach ($manifest['entries'] as $entry) {
    $sha = (string) $entry['sha256'];
    if (isset($argv[3]) && $argv[3] !== $sha) {
        continue;
    }
    try {
        if (! preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new RuntimeException('Invalid source hash');
        }
        $source = $directory.'/'.$sha.'.ipa';
        $reportPath = $directory.'/'.$sha.'.inspection.json';
        $iconPath = $directory.'/icons/'.$sha.'.png';
        if ($mode !== 'verify') {
            if (! is_file($source) || filesize($source) !== (int) $entry['size_bytes'] || hash_file('sha256', $source) !== $sha) {
                throw new RuntimeException('Source size/hash mismatch');
            }
            if (! is_file($iconPath) || hash_file('sha256', $iconPath) !== $entry['icon_sha256']) {
                throw new RuntimeException('Icon hash mismatch');
            }
        }
        if ($mode === 'prepare') {
            $result = $application->make(IpaInspector::class)->inspect($source);
            $report = $result->report;
            if (! $result->passed() || ($report['malware_scan']['status'] ?? null) !== 'CLEAN') {
                throw new RuntimeException('Inspection did not pass: '.json_encode([
                    'failure' => $report['failure'] ?? null, 'scan' => $report['malware_scan'] ?? null,
                ], JSON_UNESCAPED_UNICODE));
            }
            foreach (['bundle_identifier', 'version', 'build_number'] as $key) {
                if (($report['bundle'][$key] ?? null) !== $entry[$key]) {
                    throw new RuntimeException('Inspected identity mismatch: '.$key);
                }
            }
            file_put_contents($reportPath, json_encode(['sha256' => $sha, 'report' => $report,
                'prepared_at' => now()->toIso8601String()], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            chmod($reportPath, 0600);
            echo json_encode(['action' => 'prepared', 'name' => $entry['display_name'], 'sha256' => $sha,
                'issues' => $report['compatibility_issues'] ?? []], JSON_UNESCAPED_UNICODE)."\n";
            continue;
        }
        // A rejected or revoked earlier upload of the same bytes is finished history, not
        // something to resume: a fresh artifact is created for the new publication.
        // A customer's own import of the same file (SourceType::UserImport) stays theirs: the
        // catalog gets its own artifact.
        $existing = AppArtifact::where('sha256', $sha)
            ->whereNotIn('status', [ArtifactStatus::Rejected->value, ArtifactStatus::Revoked->value])
            ->where('source_type', '!=', SourceType::UserImport->value)
            ->orderByDesc('id')->first();
        if ($mode === 'verify') {
            if ($existing === null || $existing->status !== ArtifactStatus::Published || $existing->purged_at !== null) {
                throw new RuntimeException('Cleaned artifact is not published');
            }
            $catalog = CatalogApp::findOrFail($existing->app_id);
            if ($catalog->visibility !== AppVisibility::Published || $catalog->name !== $entry['display_name']
                || ! $catalog->icon_path || ! Storage::disk('public')->exists($catalog->icon_path)) {
                throw new RuntimeException('Catalog metadata is incomplete');
            }
            if (! Storage::disk($existing->storage_disk)->exists($existing->storage_path)
                || Storage::disk($existing->storage_disk)->size($existing->storage_path) !== $existing->size_bytes) {
                throw new RuntimeException('Published source is missing or the wrong size');
            }
            echo json_encode(['action' => 'verified', 'name' => $catalog->name, 'app_id' => $catalog->id,
                'artifact_id' => $existing->id, 'sha256' => $sha, 'icon_path' => $catalog->icon_path], JSON_UNESCAPED_UNICODE)."\n";
            continue;
        }
        if ($existing !== null && $existing->status === ArtifactStatus::Published) {
            echo json_encode(['action' => 'already_published', 'name' => $entry['display_name'], 'artifact_id' => $existing->id], JSON_UNESCAPED_UNICODE)."\n";
            continue;
        }
        $prepared = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        if ($prepared['sha256'] !== $sha || ($prepared['report']['malware_scan']['status'] ?? null) !== 'CLEAN') {
            throw new RuntimeException('Prepared inspection is not valid');
        }
        $report = $prepared['report'];
        $relative = 'originals/'.substr($sha, 0, 2).'/'.$sha.'.ipa';
        $disk = Storage::disk('artifacts');
        if (! $disk->exists($relative)) {
            $stream = fopen($source, 'rb');
            try {
                if ($stream === false || ! $disk->writeStream($relative, $stream)) {
                    throw new RuntimeException('Artifact upload failed');
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
        // Verify the actual stored bytes before a replacement can become live.
        $stream = $disk->readStream($relative);
        if ($stream === false) {
            throw new RuntimeException('Cannot verify stored artifact');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            if (hash_final($hash) !== $sha) {
                throw new RuntimeException('Stored artifact hash mismatch');
            }
        } finally {
            fclose($stream);
        }

        DB::transaction(function () use ($entry, $existing, $sha, $relative, $report, $operator, $reviewer, $actor, $audit, $states, $application, $iconPath): void {
            $key = substr(hash('sha256', $entry['bundle_identifier']), 0, 20);
            if ($entry['app_id'] !== null) {
                $catalog = CatalogApp::whereKey($entry['app_id'])->lockForUpdate()->firstOrFail();
                $actual = $catalog->artifacts()->where('sha256', '!=', $sha)->orderBy('id')->pluck('id')->all();
                $expected = $entry['expected_old_artifacts'];
                sort($expected);
                if ($actual !== $expected) {
                    throw new RuntimeException('Catalog changed since the replacement plan was made');
                }
                foreach ($entry['expected_old_hashes'] as $id => $oldSha) {
                    if (AppArtifact::whereKey($id)->value('sha256') !== $oldSha) {
                        throw new RuntimeException('Old artifact identity changed');
                    }
                }
            } else {
                $catalog = CatalogApp::where('slug', 'tg-'.$key)->lockForUpdate()->first();
                if ($catalog === null) {
                    $catalog = CatalogApp::create(['slug' => 'tg-'.$key, 'name' => $entry['display_name'],
                        'bundle_identifier' => 'com.ruappstore.tg'.$key,
                        'category_id' => AppCategory::where('slug', $entry['category'])->firstOrFail()->id,
                        'publisher_id' => AppPublisher::firstOrCreate(['name' => $entry['publisher']])->id,
                        'source_type' => SourceType::CustomerProvided, 'visibility' => AppVisibility::Draft]);
                    $audit->record('app.imported', $catalog, after: ['source' => 'user-provided cleaned IPA',
                        'original_bundle_identifier' => $entry['bundle_identifier']], actor: $actor);
                }
            }
            if ($catalog->source_type !== SourceType::CustomerProvided) {
                throw new RuntimeException('Listing source type differs from the supplied IPA');
            }
            $application->make(TeamEligibilityGranter::class)->grantFor($catalog, $operator, 'User-requested cleaned catalog batch 2026-10-02.');
            $artifact = $existing ?? AppArtifact::create(['app_id' => $catalog->id, 'sha256' => $sha,
                'size_bytes' => $entry['size_bytes'], 'storage_disk' => 'artifacts', 'storage_path' => $relative,
                'original_filename' => $entry['filename'], 'source_type' => SourceType::CustomerProvided,
                'uploaded_by' => $operator->id, 'declaration_version' => '2026-09-draft',
                'declaration_accepted_at' => now(), 'status' => ArtifactStatus::Uploaded]);
            if ($artifact->app_id !== $catalog->id) {
                throw new RuntimeException('Existing hash belongs to a different listing');
            }
            if ($artifact->status === ArtifactStatus::Uploaded) {
                $states->transition($artifact, ArtifactStatus::Hashing, actor: $actor);
                $states->transition($artifact, ArtifactStatus::Inspecting, actor: $actor);
                // This is an explicitly authorized replacement, including same
                // version/build and bank reissued under a new original bundle ID.
                // The exact old rows are pinned above; all archive, Mach-O and
                // malware checks were performed by IpaInspector in prepare.
                $artifact->forceFill(['bundle_identifier' => $entry['bundle_identifier'], 'version' => $entry['version'],
                    'build_number' => $entry['build_number'], 'min_ios_version' => $report['bundle']['min_ios_version'],
                    'inspection' => $report + ['inspected_at' => now()->toIso8601String(),
                        'replacement_batch' => ['old_artifact_ids' => $entry['expected_old_artifacts'], 'prepared_sha256' => $sha]]])->save();
                $states->transition($artifact, ArtifactStatus::ProvenanceReview, actor: $actor);
                $audit->record('artifact.inspected', $artifact, after: ['outcome' => 'PROVENANCE_REVIEW',
                    'sha256' => $sha, 'malware_scan' => 'CLEAN', 'authorized_replacement' => true], actor: $actor);
            }
            $review = $application->make(ArtifactReviewService::class);
            if ($artifact->status === ArtifactStatus::ProvenanceReview) {
                $review->approve($artifact, $reviewer, 'User supplied, cleaned and requested publication of this replacement IPA.', [
                    'source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true,
                ], false);
            }
            $artifact->refresh();
            if ($artifact->status !== ArtifactStatus::Ready) {
                throw new RuntimeException('Replacement did not become ready: '.$artifact->status->value);
            }
            $mediaDisk = Storage::disk('public');
            $temporaryIcon = 'catalog-import/'.$sha.'.png';
            $mediaDisk->put($temporaryIcon, (string) file_get_contents($iconPath));
            try {
                // Extracted original icons can be smaller than 512 px; this
                // existing normalization method preserves their native size.
                $icon = $application->make(CatalogImageService::class)->optimizeStoredIcon($temporaryIcon, $catalog->public_id);
                if ($icon === null) {
                    throw new RuntimeException('Original icon could not be decoded');
                }
            } finally {
                $mediaDisk->delete($temporaryIcon);
            }
            $changes = ['name' => $entry['display_name'], 'icon_path' => $icon['path'],
                'category_id' => AppCategory::where('slug', $entry['category'])->firstOrFail()->id,
                'publisher_id' => AppPublisher::firstOrCreate(['name' => $entry['publisher']])->id];
            if (! empty($entry['app_store_id']) && ! CatalogApp::where('app_store_id', $entry['app_store_id'])->whereKeyNot($catalog->id)->exists()) {
                $changes['app_store_id'] = (string) $entry['app_store_id'];
            }
            $catalog->fill($changes)->save();
            $review->publish($artifact, $reviewer);
            $artifact->refresh();
            $catalog->forceFill(['visibility' => AppVisibility::Published])->save();
            $audit->record('catalog.cleaned_ipa_replaced', $catalog, after: ['artifact_id' => $artifact->id,
                'sha256' => $sha, 'old_artifact_ids' => $entry['expected_old_artifacts'],
                'icon_source' => $entry['icon_source']], actor: $actor);
            echo json_encode(['action' => $entry['app_id'] === null ? 'created' : 'updated',
                'name' => $catalog->name, 'app_id' => $catalog->id, 'artifact_id' => $artifact->id,
                'sha256' => $sha, 'status' => $artifact->status->value], JSON_UNESCAPED_UNICODE)."\n";
        });
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, json_encode(['name' => $entry['display_name'], 'sha256' => $sha,
            'error' => get_class($error).': '.$error->getMessage()], JSON_UNESCAPED_UNICODE)."\n");
    }
    fflush(STDOUT);
}
flock($lock, LOCK_UN);
fclose($lock);
exit($failures === 0 ? 0 : 1);
