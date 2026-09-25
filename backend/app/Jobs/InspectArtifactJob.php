<?php

namespace App\Jobs;

use App\Models\AppArtifact;
use App\Models\PipelineJob;
use App\Services\Artifacts\ArtifactInspectionService;
use App\Services\Pipeline\PipelineJobService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * Hashes and inspects one uploaded IPA (P5-BE-02). Retries are counted on the
 * pipeline job, which operators see; the queue only carries its ID.
 */
class InspectArtifactJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const TYPE = 'InspectArtifactJob';

    /** Upper bound only; PipelineJob::max_attempts decides retries. */
    public int $tries = 10;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $pipelineJobId) {}

    public static function idempotencyKey(AppArtifact $artifact): string
    {
        return 'inspect:'.$artifact->public_id;
    }

    public function uniqueId(): string
    {
        return (string) $this->pipelineJobId;
    }

    public function handle(PipelineJobService $jobs, ArtifactInspectionService $inspection): void
    {
        $job = PipelineJob::query()->find($this->pipelineJobId);
        $artifact = $job?->subject;
        if ($job === null || ! $artifact instanceof AppArtifact) {
            return;
        }

        Context::add('correlation_id', $job->correlation_id);

        $attempt = $jobs->start($job, 'queue:'.gethostname());
        if ($attempt === null) {
            return;
        }

        try {
            $jobs->succeed($job, $attempt, $inspection->inspect($artifact));
        } catch (Throwable $error) {
            report($error);
            if ($jobs->fail($job, $attempt, $error)) {
                $this->release(min(30 * 2 ** ($job->attempt - 1), 600));
            }
        }
    }
}
