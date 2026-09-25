<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactStatus;
use App\Models\AppArtifact;
use App\Models\CatalogApp;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Inspection\InspectionResult;
use App\Services\Inspection\IpaInspector;
use App\StateMachines\StateMachine;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * Moves an uploaded artifact through HASHING and INSPECTING (IMPLEMENTATION_PLAN §5.7)
 * to PROVENANCE_REVIEW or a failure state. Safe to run again after a crash:
 * it resumes from whatever state the artifact is in.
 */
class ArtifactInspectionService
{
    /** States whose version and bundle ID no longer count against new uploads. */
    private const DISCARDED = [ArtifactStatus::Rejected, ArtifactStatus::InspectionFailed, ArtifactStatus::ProvenanceFailed];

    public function __construct(
        private readonly StateMachine $states,
        private readonly IpaInspector $inspector,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return string Result code for the pipeline job.
     */
    public function inspect(AppArtifact $artifact): string
    {
        $actor = Actor::system('inspector');

        if ($artifact->status === ArtifactStatus::Uploaded) {
            $this->states->transition($artifact, ArtifactStatus::Hashing, actor: $actor);
        }

        if ($artifact->status === ArtifactStatus::Hashing) {
            if (! $this->hashMatches($artifact)) {
                $this->finish($artifact, new InspectionResult(ArtifactStatus::Rejected, 'UPLOAD_CORRUPT', [
                    'inspector_version' => IpaInspector::VERSION,
                    'failure' => ['code' => 'UPLOAD_CORRUPT', 'message' => 'The stored file no longer matches its recorded SHA-256.', 'details' => []],
                ]), $actor);

                return 'UPLOAD_CORRUPT';
            }
            $this->states->transition($artifact, ArtifactStatus::Inspecting, actor: $actor);
        }

        if ($artifact->status !== ArtifactStatus::Inspecting) {
            return 'ALREADY_INSPECTED';
        }

        [$path, $temporary] = $this->localCopy($artifact);

        try {
            $result = $this->inspector->inspect($path);
        } finally {
            if ($temporary) {
                @unlink($path);
            }
        }

        return $this->finish($artifact, $result, $actor);
    }

    private function finish(AppArtifact $artifact, InspectionResult $result, Actor $actor): string
    {
        return DB::transaction(function () use ($artifact, $result, $actor) {
            // Serialise inspections of one app so two uploads of the same version cannot both pass.
            CatalogApp::withTrashed()->whereKey($artifact->app_id)->lockForUpdate()->first();

            if ($result->passed()) {
                $result = $this->catalogChecks($artifact, $result);
            }

            $artifact->forceFill([
                'bundle_identifier' => $result->bundleValue('bundle_identifier'),
                'version' => $result->bundleValue('version'),
                'build_number' => $result->bundleValue('build_number'),
                'min_ios_version' => $result->bundleValue('min_ios_version'),
                'inspection' => $result->report + ['inspected_at' => now()->toIso8601ZuluString()],
            ])->save();

            $this->states->transition(
                $artifact,
                $result->outcome,
                reason: $result->report['failure']['message'] ?? null,
                actor: $actor,
                extra: ['status_reason' => $result->failureCode],
            );

            $this->audit->record('artifact.inspected', $artifact, after: [
                'outcome' => $result->outcome->value,
                'failure_code' => $result->failureCode,
                'bundle_identifier' => $result->bundleValue('bundle_identifier'),
                'version' => $result->bundleValue('version'),
                'build_number' => $result->bundleValue('build_number'),
                'compatibility_issues' => array_column($result->report['compatibility_issues'] ?? [], 'code'),
                'malware_scan' => $result->report['malware_scan']['status'] ?? null,
            ], actor: $actor);

            return $result->failureCode ?? 'INSPECTED';
        });
    }

    /**
     * Checks that need the catalog: the linked version, the app's bundle ID
     * and version uniqueness (IMPLEMENTATION_PLAN §5.7).
     */
    private function catalogChecks(AppArtifact $artifact, InspectionResult $result): InspectionResult
    {
        $bundleId = $result->bundleValue('bundle_identifier');
        $version = $result->bundleValue('version');
        $build = $result->bundleValue('build_number');

        $linked = $artifact->appVersion;
        if ($linked !== null && ($linked->version !== $version || $linked->build_number !== $build)) {
            return self::failed($result, ArtifactStatus::InspectionFailed, 'VERSION_MISMATCH', 'The IPA version differs from the catalog version it was uploaded for.', [
                'catalog' => ['version' => $linked->version, 'build_number' => $linked->build_number],
                'ipa' => ['version' => $version, 'build_number' => $build],
            ]);
        }

        $siblings = AppArtifact::query()
            ->where('app_id', $artifact->app_id)
            ->whereKeyNot($artifact->getKey())
            ->whereNotIn('status', self::DISCARDED)
            ->whereNotNull('bundle_identifier');

        $other = (clone $siblings)->where('bundle_identifier', '!=', $bundleId)->first();
        if ($other !== null) {
            return self::failed($result, ArtifactStatus::InspectionFailed, 'BUNDLE_ID_MISMATCH', 'Another build of this app uses a different bundle ID.', [
                'artifact_id' => $other->public_id,
                'bundle_identifier' => $other->bundle_identifier,
            ]);
        }

        $same = (clone $siblings)->where('version', $version)->where('build_number', $build)->first();
        if ($same !== null) {
            return self::failed($result, ArtifactStatus::Rejected, 'VERSION_EXISTS', 'This version and build already exist for the app.', [
                'artifact_id' => $same->public_id,
            ]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function failed(InspectionResult $result, ArtifactStatus $outcome, string $code, string $message, array $details): InspectionResult
    {
        return new InspectionResult($outcome, $code, $result->report + [
            'failure' => ['code' => $code, 'message' => $message, 'details' => $details],
        ]);
    }

    private function hashMatches(AppArtifact $artifact): bool
    {
        $stream = $this->disk($artifact)->readStream($artifact->storage_path);
        if ($stream === null) {
            return false;
        }

        $context = hash_init('sha256');
        $size = hash_update_stream($context, $stream);
        fclose($stream);

        return $size === $artifact->size_bytes && hash_equals($artifact->sha256, hash_final($context));
    }

    /**
     * ZipArchive needs a real path; remote disks are copied to a private temporary file.
     *
     * @return array{0: string, 1: bool} Path and whether it is a temporary copy.
     */
    private function localCopy(AppArtifact $artifact): array
    {
        $disk = $this->disk($artifact);
        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return [$disk->path($artifact->storage_path), false];
        }

        $path = tempnam(sys_get_temp_dir(), 'ipa');
        $source = $disk->readStream($artifact->storage_path);
        if ($path === false || $source === null) {
            throw new RuntimeException('The artifact file cannot be read.');
        }
        chmod($path, 0600);
        $target = fopen($path, 'wb');
        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        return [$path, true];
    }

    private function disk(AppArtifact $artifact): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk($artifact->storage_disk);
    }
}
