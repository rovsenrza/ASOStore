<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Exceptions\IllegalStateTransition;
use App\Jobs\InspectArtifactJob;
use App\Models\AppArtifact;
use App\Models\ArtifactReview;
use App\Models\CatalogApp;
use App\Models\PipelineJob;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Inspection\MalwareScanner;
use App\Services\Pipeline\PipelineJobService;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Human decisions on an artifact after inspection (FULL_PLAN §5.1.1, §12;
 * IMPLEMENTATION_PLAN P5-BE-03): provenance review, quarantine release,
 * compatibility check, publish, revoke and re-inspection.
 */
class ArtifactReviewService
{
    public function __construct(
        private readonly StateMachine $states,
        private readonly AuditService $audit,
        private readonly CompatibilityChecker $compatibility,
        private readonly PipelineJobService $jobs,
    ) {}

    /**
     * @param  array<string, bool>  $checklist
     */
    public function approve(AppArtifact $artifact, User $reviewer, ?string $reason, array $checklist, bool $scanAcknowledged): ArtifactReview
    {
        return DB::transaction(function () use ($artifact, $reviewer, $reason, $checklist, $scanAcknowledged) {
            $artifact = $this->lock($artifact);
            $actor = Actor::user($reviewer);

            if ($artifact->status === ArtifactStatus::Quarantined) {
                // A released file goes back to a full provenance review, never straight to READY.
                $this->requireReason($reason);
                $this->states->transition($artifact, ArtifactStatus::ProvenanceReview, $reason, $actor, extra: ['status_reason' => 'RELEASED_FROM_QUARANTINE']);

                return $this->record($artifact, $reviewer, ArtifactReview::RELEASED, ArtifactStatus::Quarantined, $reason);
            }

            if ($artifact->status !== ArtifactStatus::ProvenanceReview) {
                throw new IllegalStateTransition('AppArtifact', $artifact->status, ArtifactStatus::CompatibilityCheck);
            }

            $this->assertIndependent($artifact, $reviewer);
            $this->assertChecklist($checklist);
            if (($artifact->inspection['malware_scan']['status'] ?? null) !== MalwareScanner::CLEAN && ! $scanAcknowledged) {
                throw new ApiException(ErrorCode::ValidationFailed, 'Подтвердите результат антивирусной проверки.', [
                    'fields' => ['acknowledge_scan_result' => ['The malware scan did not report this file as clean.']],
                ]);
            }

            $this->states->transition($artifact, ArtifactStatus::CompatibilityCheck, $reason, $actor, extra: ['status_reason' => null]);
            $review = $this->record($artifact, $reviewer, ArtifactReview::APPROVED, ArtifactStatus::ProvenanceReview, $reason, $checklist, $scanAcknowledged);

            $app = CatalogApp::withTrashed()->findOrFail($artifact->app_id);
            $result = $this->compatibility->check($artifact, $app);
            $artifact->forceFill([
                'inspection' => ($artifact->inspection ?? []) + ['compatibility' => $result + ['checked_at' => now()->toIso8601ZuluString()]],
            ])->save();

            $blocking = $result['blocking'][0] ?? null;
            $this->states->transition(
                $artifact,
                $blocking === null ? ArtifactStatus::Ready : ArtifactStatus::Rejected,
                $blocking['message'] ?? null,
                Actor::system('compatibility'),
                extra: ['status_reason' => $blocking['code'] ?? null],
            );

            return $review;
        });
    }

    public function reject(AppArtifact $artifact, User $reviewer, ?string $reason): ArtifactReview
    {
        $this->requireReason($reason);

        return DB::transaction(function () use ($artifact, $reviewer, $reason) {
            $artifact = $this->lock($artifact);
            $from = $artifact->status;
            [$to, $code] = match ($from) {
                ArtifactStatus::ProvenanceReview => [ArtifactStatus::ProvenanceFailed, 'REVIEW_REJECTED'],
                ArtifactStatus::Quarantined => [ArtifactStatus::Rejected, 'QUARANTINE_REJECTED'],
                default => throw new IllegalStateTransition('AppArtifact', $from, ArtifactStatus::Rejected),
            };

            $this->states->transition($artifact, $to, $reason, Actor::user($reviewer), extra: ['status_reason' => $code]);

            return $this->record($artifact, $reviewer, ArtifactReview::REJECTED, $from, $reason);
        });
    }

    /**
     * READY → PUBLISHED. The previous published build of the app is superseded,
     * and the matching catalog version is created or linked.
     */
    public function publish(AppArtifact $artifact, User $actor): AppArtifact
    {
        return DB::transaction(function () use ($artifact, $actor) {
            $app = CatalogApp::withTrashed()->whereKey($artifact->app_id)->lockForUpdate()->firstOrFail();
            $artifact = $this->lock($artifact);

            if ($artifact->status !== ArtifactStatus::Ready) {
                throw new IllegalStateTransition('AppArtifact', $artifact->status, ArtifactStatus::Published);
            }
            if ($app->trashed()) {
                throw new ApiException(ErrorCode::Conflict, 'Приложение удалено из каталога.');
            }
            // Checked again here: the allowed list may have narrowed since the compatibility check.
            if (! CompatibilityChecker::publishable($artifact)) {
                throw new ApiException(ErrorCode::SourceTypeNotPublishable, details: ['source_type' => $artifact->source_type->value]);
            }

            $by = Actor::user($actor);
            $previous = AppArtifact::query()
                ->where('app_id', $app->id)
                ->where('status', ArtifactStatus::Published->value)
                ->get();
            foreach ($previous as $old) {
                $this->states->transition($old, ArtifactStatus::Expired, 'Superseded by '.$artifact->public_id, $by, extra: ['status_reason' => 'SUPERSEDED']);
            }

            $version = $app->versions()->firstOrCreate(
                ['version' => $artifact->version, 'build_number' => $artifact->build_number],
                ['min_ios_version' => $artifact->min_ios_version, 'released_at' => now()],
            );
            $version->released_at ??= now();
            $version->save();

            $this->states->transition($artifact, ArtifactStatus::Published, actor: $by, extra: ['app_version_id' => $version->id, 'status_reason' => null]);
            $this->audit->record('artifact.published', $artifact, after: [
                'app_id' => $app->public_id,
                'version' => $artifact->version,
                'build_number' => $artifact->build_number,
                'superseded' => $previous->pluck('public_id')->all(),
            ], actor: $by);

            return $artifact;
        });
    }

    public function revoke(AppArtifact $artifact, User $actor, ?string $reason): AppArtifact
    {
        $this->requireReason($reason);

        return DB::transaction(function () use ($artifact, $actor, $reason) {
            $artifact = $this->lock($artifact);
            $this->states->transition($artifact, ArtifactStatus::Revoked, $reason, Actor::user($actor), extra: ['status_reason' => 'REVOKED_BY_OPERATOR']);

            return $artifact;
        });
    }

    /**
     * INSPECTION_FAILED → INSPECTING with a new pipeline job, e.g. after the
     * inspector was fixed or limits were raised.
     */
    public function reinspect(AppArtifact $artifact, User $actor, ?string $reason): PipelineJob
    {
        $job = DB::transaction(function () use ($artifact, $actor, $reason) {
            $artifact = $this->lock($artifact);
            $this->states->transition($artifact, ArtifactStatus::Inspecting, $reason, Actor::user($actor), extra: ['status_reason' => null]);

            return $this->jobs->create(
                InspectArtifactJob::TYPE,
                InspectArtifactJob::idempotencyKey($artifact).':'.($artifact->pipelineJobs()->count() + 1),
                $artifact,
                ['artifact_id' => $artifact->public_id, 'reinspection' => true],
                Actor::user($actor),
            );
        });

        InspectArtifactJob::dispatch($job->id)->afterCommit();

        return $job;
    }

    private function lock(AppArtifact $artifact): AppArtifact
    {
        return AppArtifact::query()->whereKey($artifact->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, bool>|null  $checklist
     */
    private function record(
        AppArtifact $artifact,
        User $reviewer,
        string $decision,
        ArtifactStatus $from,
        ?string $reason,
        ?array $checklist = null,
        bool $scanAcknowledged = false,
    ): ArtifactReview {
        return ArtifactReview::create([
            'artifact_id' => $artifact->id,
            'reviewer_id' => $reviewer->id,
            'decision' => $decision,
            'from_status' => $from->value,
            'to_status' => $artifact->status->value,
            'reason' => $reason,
            'checklist_version' => $checklist === null ? null : config('storefront.artifacts.review_checklist_version'),
            'checklist' => $checklist,
            'scan_result_acknowledged' => $scanAcknowledged,
        ]);
    }

    private function requireReason(?string $reason): void
    {
        if ($reason === null || trim($reason) === '') {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['fields' => ['reason' => ['Укажите причину.']]]);
        }
    }

    /**
     * @param  array<string, bool>  $checklist
     */
    private function assertChecklist(array $checklist): void
    {
        $missing = array_values(array_filter(
            config('storefront.artifacts.review_checklist'),
            fn (string $item) => ($checklist[$item] ?? false) !== true,
        ));

        if ($missing !== []) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Отметьте все пункты проверки.', [
                'fields' => ['checklist' => ['Unconfirmed: '.implode(', ', $missing)]],
                'missing' => $missing,
            ]);
        }
    }

    private function assertIndependent(AppArtifact $artifact, User $reviewer): void
    {
        if (config('storefront.artifacts.independent_review') && $artifact->uploaded_by === $reviewer->id) {
            throw new ApiException(ErrorCode::Forbidden, 'Файл должен проверить другой сотрудник.');
        }
    }
}
