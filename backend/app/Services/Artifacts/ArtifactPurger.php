<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactStatus;
use App\Models\AppArtifact;
use App\Models\CatalogApp;
use App\Models\SignedBuild;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Installations\InstallationService;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Frees the storage of a deleted listing: every build ends (published ones are revoked,
 * ones still in review are rejected), and the original IPAs and the per-device signed
 * builds are deleted from disk. The rows stay as audit evidence, marked purged.
 */
class ArtifactPurger
{
    public function __construct(
        private readonly StateMachine $states,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{artifacts: int, signed_builds: int, bytes: int}
     */
    public function purgeApp(CatalogApp $app, Actor $actor, string $reason): array
    {
        $summary = ['artifacts' => 0, 'signed_builds' => 0, 'bytes' => 0];

        foreach (AppArtifact::query()->where('app_id', $app->id)->whereNull('purged_at')->get() as $artifact) {
            DB::transaction(function () use ($artifact, $actor, $reason) {
                $artifact = AppArtifact::query()->lockForUpdate()->findOrFail($artifact->id);
                $end = match ($artifact->status) {
                    ArtifactStatus::Published, ArtifactStatus::Ready => ArtifactStatus::Revoked,
                    ArtifactStatus::ProvenanceReview => ArtifactStatus::ProvenanceFailed,
                    ArtifactStatus::Uploaded, ArtifactStatus::Hashing, ArtifactStatus::Inspecting,
                    ArtifactStatus::CompatibilityCheck, ArtifactStatus::Quarantined => ArtifactStatus::Rejected,
                    default => null,
                };
                if ($end !== null) {
                    $this->states->transition($artifact, $end, $reason, $actor, extra: ['status_reason' => 'APP_DELETED']);
                    if ($end === ArtifactStatus::Revoked) {
                        app(InstallationService::class)->artifactWithdrawn($artifact, 'REVOKED');
                    }
                }
            });

            foreach (SignedBuild::query()->where('artifact_id', $artifact->id)->whereNull('purged_at')->whereNotNull('storage_path')->get() as $build) {
                $summary['bytes'] += $this->delete($build->storage_path, 'artifacts', fn () => false);
                $build->forceFill(['purged_at' => now()])->save();
                $summary['signed_builds']++;
            }

            // A newer upload of the same file under another listing shares the path; keep it for that one.
            $summary['bytes'] += $this->delete($artifact->storage_path, $artifact->storage_disk, fn () => AppArtifact::query()
                ->whereKeyNot($artifact->id)->where('storage_path', $artifact->storage_path)->whereNull('purged_at')->exists());
            $artifact->forceFill(['purged_at' => now()])->save();
            $summary['artifacts']++;
        }

        $this->audit->record('app.storage_purged', $app, after: $summary, reason: $reason, actor: $actor);

        return $summary;
    }

    /**
     * @param  callable(): bool  $shared
     */
    private function delete(?string $path, ?string $disk, callable $shared): int
    {
        $storage = Storage::disk($disk ?: 'artifacts');
        if ($path === null || $shared() || ! $storage->exists($path)) {
            return 0;
        }
        $bytes = (int) $storage->size($path);
        $storage->delete($path);

        return $bytes;
    }
}
