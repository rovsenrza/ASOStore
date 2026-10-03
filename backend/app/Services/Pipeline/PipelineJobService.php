<?php

namespace App\Services\Pipeline;

use App\Enums\ErrorCode;
use App\Enums\PipelineJobStatus;
use App\Exceptions\ApiException;
use App\Exceptions\IllegalStateTransition;
use App\Jobs\CleanArtifactJob;
use App\Jobs\FetchImportJob;
use App\Jobs\InspectArtifactJob;
use App\Jobs\PrepareSigningJob;
use App\Jobs\VerifySignatureJob;
use App\Models\PipelineJob;
use App\Models\PipelineJobAttempt;
use App\Services\Audit\Actor;
use App\Services\Signing\SigningService;
use App\StateMachines\StateMachine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Operator-visible domain jobs (FULL_PLAN §8.3, IMPLEMENTATION_PLAN §5.1).
 * Each run is one attempt row; status changes go through the state machine
 * so they are audited.
 */
class PipelineJobService
{
    public const WORKER_LOST = 'The worker stopped before the attempt finished.';

    public function __construct(private readonly StateMachine $states) {}

    /**
     * Returns the existing job when the idempotency key was already used.
     *
     * @param  array<string, mixed>  $payload  Visible to operators; never put secrets here.
     */
    public function create(string $type, string $idempotencyKey, Model $subject, array $payload = [], ?Actor $actor = null, ?int $maxAttempts = null): PipelineJob
    {
        $actor ??= Actor::current();

        return PipelineJob::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], array_filter(['max_attempts' => $maxAttempts]) + [
            'type' => $type,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'correlation_id' => Context::get('correlation_id', Context::get('request_id')) ?? strtolower((string) Str::ulid()),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'payload' => $payload,
            // Set here, not by the column default: MySQL's CURRENT_TIMESTAMP is in
            // the server's time zone, while every comparison uses the app's UTC now().
            'available_at' => now(),
        ]);
    }

    /**
     * Moves a queued (or retry-waiting) job to RUNNING and opens an attempt.
     * Returns null when the job is not runnable, e.g. another worker has it.
     */
    public function start(PipelineJob $job, string $worker): ?PipelineJobAttempt
    {
        try {
            if ($job->status === PipelineJobStatus::FailedRetryable) {
                $this->states->transition($job, PipelineJobStatus::Queued, 'Retry.', $this->actor());
            }
            if ($job->status !== PipelineJobStatus::Queued) {
                return null;
            }

            $this->states->transition($job, PipelineJobStatus::Running, actor: $this->actor(), extra: [
                'attempt' => $job->attempt + 1,
                'started_at' => now(),
                'finished_at' => null,
            ]);
        } catch (IllegalStateTransition) {
            return null;
        }

        return $job->attempts()->create([
            'attempt' => $job->attempt,
            'worker' => $worker,
            'started_at' => now(),
        ]);
    }

    /**
     * A queue worker that dies mid-attempt (timeout, restart) leaves its job RUNNING and the
     * queue delivers it again. Closes that attempt as failed so the job can run again.
     * Returns null when the job was not abandoned, otherwise whether it may be retried.
     */
    public function abandonStale(PipelineJob $job, int $olderThanSeconds): ?bool
    {
        if ($job->status !== PipelineJobStatus::Running || $job->lease_owner !== null
            || $job->started_at === null || $job->started_at->gt(now()->subSeconds($olderThanSeconds))) {
            return null;
        }

        $attempt = $job->attempts()->whereNull('finished_at')->latest('id')->first()
            ?? $job->attempts()->create(['attempt' => $job->attempt, 'worker' => 'unknown', 'started_at' => $job->started_at]);

        return $this->fail($job, $attempt, new RuntimeException(self::WORKER_LOST));
    }

    public function succeed(PipelineJob $job, PipelineJobAttempt $attempt, string $resultCode): void
    {
        $this->states->transition($job, PipelineJobStatus::Succeeded, actor: $this->actor(), extra: [
            'result_code' => $resultCode,
            'finished_at' => now(),
            'error_class' => null,
            'error_message_redacted' => null,
        ]);

        $attempt->update(['finished_at' => now(), 'result_code' => $resultCode]);
    }

    /**
     * Records a failed attempt. Returns true when the job may be retried.
     */
    public function fail(PipelineJob $job, PipelineJobAttempt $attempt, Throwable $error): bool
    {
        $retryable = $job->attempt < $job->max_attempts;
        $details = [
            'error_class' => $error::class,
            'error_message_redacted' => mb_substr($error->getMessage(), 0, 1000),
        ];

        $this->states->transition(
            $job,
            $retryable ? PipelineJobStatus::FailedRetryable : PipelineJobStatus::FailedPermanent,
            actor: $this->actor(),
            extra: $details + ['finished_at' => now()],
        );

        $attempt->update($details + ['finished_at' => now(), 'result_code' => 'ERROR']);

        return $retryable;
    }

    /**
     * The work asked to wait (e.g. Apple rate limiting). The attempt is closed
     * without counting against the retry budget.
     */
    public function postpone(PipelineJob $job, PipelineJobAttempt $attempt, RetryLater $wait): void
    {
        $this->states->transition($job, PipelineJobStatus::Queued, $wait->getMessage(), $this->actor(), extra: [
            'available_at' => now()->addSeconds($wait->seconds),
            'max_attempts' => $job->max_attempts + 1,
        ]);
        $attempt->update(['finished_at' => now(), 'result_code' => 'POSTPONED', 'error_message_redacted' => mb_substr($wait->getMessage(), 0, 1000)]);
    }

    /**
     * Hands a queued runner job to a runner (IMPLEMENTATION_PLAN D9, P6-BE-02).
     * Must be called inside a transaction that holds the job row lock.
     */
    public function lease(PipelineJob $job, string $runnerKey, int $seconds): PipelineJobAttempt
    {
        $actor = Actor::worker($runnerKey);
        $this->states->transition($job, PipelineJobStatus::Leased, actor: $actor, extra: [
            'lease_owner' => $runnerKey,
            'lease_expires_at' => now()->addSeconds($seconds),
            'attempt' => $job->attempt + 1,
            'started_at' => now(),
            'finished_at' => null,
        ]);
        $this->states->transition($job, PipelineJobStatus::Running, actor: $actor);

        return $job->attempts()->create(['attempt' => $job->attempt, 'worker' => $runnerKey, 'started_at' => now()]);
    }

    public function extendLease(PipelineJob $job, int $seconds): void
    {
        $job->forceFill(['lease_expires_at' => now()->addSeconds($seconds)])->save();
    }

    /**
     * Returns runner jobs whose lease ran out to the queue (the runner died or
     * lost its connection). Returns the recovered jobs.
     *
     * @return list<PipelineJob>
     */
    public function recoverExpiredLeases(): array
    {
        $recovered = [];
        $expired = PipelineJob::query()
            ->whereIn('status', [PipelineJobStatus::Leased->value, PipelineJobStatus::Running->value])
            ->whereNotNull('lease_owner')
            ->where('lease_expires_at', '<', now())
            ->get();

        foreach ($expired as $job) {
            try {
                $this->states->transition($job, PipelineJobStatus::Queued, 'Lease expired.', $this->actor(), extra: [
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'available_at' => now(),
                ]);
                $job->attempts()->where('attempt', $job->attempt)->whereNull('finished_at')
                    ->update(['finished_at' => now(), 'result_code' => 'LEASE_EXPIRED']);
                $recovered[] = $job;
            } catch (IllegalStateTransition) {
                // Finished concurrently.
            }
        }

        return $recovered;
    }

    public function currentAttempt(PipelineJob $job): ?PipelineJobAttempt
    {
        return $job->attempts()->where('attempt', $job->attempt)->first();
    }

    /**
     * Operator retry of a failed job (FULL_PLAN §9 POST /admin/jobs/{id}/retry).
     * The job gets one more attempt; the idempotency key stays the same, so the
     * work itself is never duplicated.
     */
    public function retry(PipelineJob $job, Actor $actor, string $reason): PipelineJob
    {
        if (! in_array($job->status, [PipelineJobStatus::FailedPermanent, PipelineJobStatus::FailedRetryable], true)) {
            throw new IllegalStateTransition('PipelineJob', $job->status, PipelineJobStatus::Queued);
        }

        $this->states->transition($job, PipelineJobStatus::Queued, $reason, $actor, extra: ['available_at' => now()]);
        $this->dispatch($job);

        return $job;
    }

    public function dispatch(PipelineJob $job): void
    {
        match ($job->type) {
            InspectArtifactJob::TYPE => InspectArtifactJob::dispatch($job->id)->afterCommit(),
            CleanArtifactJob::TYPE => CleanArtifactJob::dispatch($job->id)->afterCommit(),
            FetchImportJob::TYPE => FetchImportJob::dispatch($job->id)->afterCommit(),
            PrepareSigningJob::TYPE => PrepareSigningJob::dispatch($job->id)->afterCommit(),
            VerifySignatureJob::TYPE => VerifySignatureJob::dispatch($job->id)->afterCommit(),
            // Runner jobs are picked up by a runner lease; nothing to dispatch.
            SigningService::RUNNER_JOB_TYPE => null,
            default => throw new ApiException(ErrorCode::Conflict, 'Этот тип задачи нельзя перезапустить.', ['type' => $job->type]),
        };
    }

    private function actor(): Actor
    {
        return Actor::system('queue');
    }
}
