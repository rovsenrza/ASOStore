<?php

namespace App\Jobs;

use App\Models\PipelineJob;
use App\Services\Pipeline\PipelineJobService;
use App\Services\Pipeline\RetryLater;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * A domain job run by the Laravel queue and tracked as a pipeline job
 * (FULL_PLAN §8.3). Retries are counted on the pipeline job, which operators
 * see; the queue payload only carries its ID.
 */
abstract class PipelineQueueJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Upper bound only; PipelineJob::max_attempts decides retries. */
    public int $tries = 20;

    public int $uniqueFor = 3600;

    public function __construct(public int $pipelineJobId) {}

    /**
     * Does the work and returns the result code stored on the job.
     *
     * @throws RetryLater when an outside service asked to be called again later
     */
    abstract protected function run(PipelineJob $job): string;

    public function uniqueId(): string
    {
        return (string) $this->pipelineJobId;
    }

    public function handle(PipelineJobService $jobs): void
    {
        $job = PipelineJob::query()->find($this->pipelineJobId);
        if ($job === null) {
            return;
        }

        Context::add('correlation_id', $job->correlation_id);

        $attempt = $jobs->start($job, 'queue:'.gethostname());
        if ($attempt === null) {
            return;
        }

        try {
            $jobs->succeed($job, $attempt, $this->run($job));
        } catch (RetryLater $wait) {
            $jobs->postpone($job, $attempt, $wait);
            $this->release($wait->seconds);
        } catch (Throwable $error) {
            report($error);
            if ($jobs->fail($job, $attempt, $error)) {
                $this->release(min(30 * 2 ** ($job->attempt - 1), 600));
            } else {
                $this->failedPermanently($job, $error);
            }
        }
    }

    /**
     * Hook for subject clean-up once no retries are left.
     */
    protected function failedPermanently(PipelineJob $job, Throwable $error): void {}
}
