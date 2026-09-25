<?php

namespace App\Services\Pipeline;

use App\Enums\PipelineJobStatus;
use App\Exceptions\IllegalStateTransition;
use App\Models\PipelineJob;
use App\Models\PipelineJobAttempt;
use App\Services\Audit\Actor;
use App\StateMachines\StateMachine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Throwable;

/**
 * Operator-visible domain jobs (FULL_PLAN §8.3, IMPLEMENTATION_PLAN §5.1).
 * Each run is one attempt row; status changes go through the state machine
 * so they are audited.
 */
class PipelineJobService
{
    public function __construct(private readonly StateMachine $states) {}

    /**
     * Returns the existing job when the idempotency key was already used.
     *
     * @param  array<string, mixed>  $payload  Visible to operators; never put secrets here.
     */
    public function create(string $type, string $idempotencyKey, Model $subject, array $payload = [], ?Actor $actor = null): PipelineJob
    {
        $actor ??= Actor::current();

        return PipelineJob::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
            'type' => $type,
            'actor_type' => $actor->type,
            'actor_id' => $actor->id,
            'correlation_id' => Context::get('correlation_id', Context::get('request_id')) ?? strtolower((string) Str::ulid()),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'payload' => $payload,
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

    private function actor(): Actor
    {
        return Actor::system('queue');
    }
}
