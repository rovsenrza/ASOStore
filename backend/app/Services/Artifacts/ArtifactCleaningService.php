<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Jobs\CleanArtifactJob;
use App\Jobs\InspectArtifactJob;
use App\Models\AppArtifact;
use App\Models\PipelineJob;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Pipeline\PipelineJobService;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Cleaned copies of uploaded IPAs (tools/ipa-cleaner). A copy is a new artifact that names
 * its source and goes through the whole pipeline again: inspection, review, publication.
 * The source leaves the review queue and stays as evidence; a published source keeps
 * serving installs until its copy is published and supersedes it.
 */
class ArtifactCleaningService
{
    /** States whose file can be cleaned (still stored, not withdrawn). */
    public const CLEANABLE = [
        ArtifactStatus::ProvenanceReview, ArtifactStatus::Quarantined, ArtifactStatus::CompatibilityCheck, ArtifactStatus::Ready,
        ArtifactStatus::Published, ArtifactStatus::InspectionFailed, ArtifactStatus::Rejected, ArtifactStatus::ProvenanceFailed,
    ];

    private const DISCARDED = [ArtifactStatus::Rejected->value, ArtifactStatus::InspectionFailed->value, ArtifactStatus::ProvenanceFailed->value];

    public function __construct(
        private readonly StateMachine $states,
        private readonly PipelineJobService $jobs,
        private readonly AuditService $audit,
        private readonly IpaCleaner $cleaner,
    ) {}

    public static function cleanable(AppArtifact $artifact): bool
    {
        return in_array($artifact->status, self::CLEANABLE, true) && $artifact->getAttribute('purged_at') === null;
    }

    /**
     * @param  array{remove?: list<string>, remove_extensions?: list<string>, opt_in?: list<string>, recommended?: bool, fix_metadata?: bool}  $selection
     */
    public function request(AppArtifact $artifact, User $user, array $selection, string $reason, ?string $ip): PipelineJob
    {
        if (! $this->cleaner->enabled()) {
            throw new ApiException(ErrorCode::ServiceUnavailable, 'Инструмент очистки IPA не установлен на сервере.');
        }
        if (! self::cleanable($artifact)) {
            throw new ApiException(ErrorCode::Conflict, 'Этот артефакт сейчас нельзя очистить.', ['status' => $artifact->status->value]);
        }
        $selection = [
            'remove' => array_values(array_unique($selection['remove'] ?? [])),
            'remove_extensions' => array_values(array_unique($selection['remove_extensions'] ?? [])),
            'opt_in' => array_values(array_unique($selection['opt_in'] ?? [])),
            'recommended' => (bool) ($selection['recommended'] ?? false),
            'fix_metadata' => (bool) ($selection['fix_metadata'] ?? false),
        ];
        if ($selection['remove'] === [] && $selection['remove_extensions'] === [] && $selection['opt_in'] === [] && ! $selection['recommended'] && ! $selection['fix_metadata']) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['selection' => 'Выберите, что удалить или исправить.']);
        }

        $actor = Actor::user($user);
        $job = DB::transaction(function () use ($artifact, $selection, $reason, $ip, $user, $actor) {
            $attempt = PipelineJob::query()->where('subject_type', $artifact->getMorphClass())->where('subject_id', $artifact->id)
                ->where('type', CleanArtifactJob::TYPE)->count() + 1;
            // One attempt: a refusal does not change on retry; the jobs page can still run it again.
            $job = $this->jobs->create(CleanArtifactJob::TYPE, 'clean:'.$artifact->public_id.':'.$attempt, $artifact, [
                'artifact_id' => $artifact->public_id,
                'selection' => $selection,
                'requested_by' => $user->id,
                'reason' => $reason,
                'ip' => $ip,
            ], $actor, maxAttempts: 1);
            $this->audit->record('artifact.cleaning_requested', $artifact, after: ['selection' => $selection, 'job_id' => $job->public_id], reason: $reason, actor: $actor);

            return $job;
        });
        CleanArtifactJob::dispatch($job->id)->afterCommit();

        return $job;
    }

    /**
     * Runs in CleanArtifactJob. Returns the job's result code; a refusal fails the job with
     * the cleaner's explanation.
     */
    public function clean(AppArtifact $artifact, PipelineJob $job): string
    {
        $payload = $job->payload;
        $disk = Storage::disk($artifact->storage_disk);
        $source = LocalArtifactFile::open($disk, $artifact->storage_path);
        $output = LocalArtifactFile::temporaryPath('clean');
        try {
            if (! hash_equals($artifact->sha256, (string) hash_file('sha256', $source->path))) {
                throw new RuntimeException('The stored file no longer matches its SHA-256.');
            }
            $report = $this->cleaner->clean($source->path, $output, $payload['selection']);
            if (! ($report['changed'] ?? false)) {
                return 'NOTHING_TO_CLEAN';
            }
            $sha256 = (string) hash_file('sha256', $output);
            $size = (int) filesize($output);
            if (! hash_equals((string) ($report['output_sha256'] ?? ''), $sha256)) {
                throw new RuntimeException('The cleaner report does not describe its output file.');
            }
            // The same cleanup already produced this file.
            if (AppArtifact::query()->where('sha256', $sha256)->whereNotIn('status', self::DISCARDED)->exists()) {
                return 'ALREADY_EXISTS';
            }

            $path = 'originals/'.substr($sha256, 0, 2).'/'.$sha256.'.ipa';
            $stream = fopen($output, 'rb') ?: throw new RuntimeException('The cleaned file cannot be read.');
            try {
                $disk->put($path, $stream);
            } finally {
                fclose($stream);
            }

            DB::transaction(function () use ($artifact, $job, $payload, $report, $sha256, $size, $path) {
                $requester = User::query()->find($payload['requested_by'] ?? null);
                $actor = $requester ? Actor::user($requester) : Actor::system('ipa-cleaner');
                $copy = AppArtifact::create([
                    'app_id' => $artifact->app_id,
                    'app_version_id' => $artifact->app_version_id,
                    'derived_from_artifact_id' => $artifact->id,
                    'sha256' => $sha256,
                    'size_bytes' => $size,
                    'storage_disk' => 'artifacts',
                    'storage_path' => $path,
                    'original_filename' => $artifact->original_filename,
                    'source_type' => $artifact->source_type,
                    'uploaded_by' => $requester->id ?? $artifact->getAttribute('uploaded_by'),
                    'declaration_version' => $artifact->getAttribute('declaration_version'),
                    'declaration_accepted_at' => now(),
                    'declaration_ip' => $payload['ip'] ?? null,
                    'cleaning_report' => $report,
                    'status' => ArtifactStatus::Uploaded,
                ]);
                $this->retire($artifact, $copy, $actor);
                $this->audit->record('artifact.cleaned', $copy, after: [
                    'source_artifact_id' => $artifact->public_id,
                    'source_sha256' => $artifact->sha256,
                    'sha256' => $sha256,
                    'removed_modules' => array_column($report['removed_modules'] ?? [], 'path'),
                    'removed_extensions' => $report['removed_extensions'] ?? [],
                    'patched_libraries' => array_keys($report['patched_libraries'] ?? []),
                    'metadata_fixes' => $report['metadata_fixes'] ?? [],
                    'job_id' => $job->public_id,
                ], reason: $payload['reason'] ?? null, actor: $actor);

                $inspection = $this->jobs->create(InspectArtifactJob::TYPE, InspectArtifactJob::idempotencyKey($copy), $copy, ['artifact_id' => $copy->public_id], $actor);
                InspectArtifactJob::dispatch($inspection->id)->afterCommit();
            });

            return 'CLEANED';
        } finally {
            $source->release();
            @unlink($output);
        }
    }

    /** The source leaves the review queue; a published one is superseded when its copy is published. */
    private function retire(AppArtifact $artifact, AppArtifact $copy, Actor $actor): void
    {
        $artifact = AppArtifact::query()->lockForUpdate()->findOrFail($artifact->id);
        $end = match ($artifact->status) {
            ArtifactStatus::ProvenanceReview => ArtifactStatus::ProvenanceFailed,
            ArtifactStatus::Quarantined, ArtifactStatus::CompatibilityCheck => ArtifactStatus::Rejected,
            ArtifactStatus::Ready => ArtifactStatus::Revoked,
            default => null,
        };
        if ($end !== null) {
            $this->states->transition($artifact, $end, 'Replaced by cleaned copy '.$copy->public_id, $actor, extra: ['status_reason' => 'REPLACED_BY_CLEANED_COPY']);
        }
    }
}
