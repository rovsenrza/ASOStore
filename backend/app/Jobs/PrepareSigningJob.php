<?php

namespace App\Jobs;

use App\Models\PipelineJob;
use App\Models\SignedBuild;
use App\Services\Signing\SigningService;
use Throwable;

/**
 * Provisions the ad hoc profile for a build, then queues it for a runner (P6-BE-03).
 */
class PrepareSigningJob extends PipelineQueueJob
{
    public const TYPE = 'PrepareSigningJob';

    /**
     * Unique per queue: a speculative preparation waiting on the background queue can be
     * put on the customer queue too when someone asks to install (SigningService::promote).
     */
    public function uniqueId(): string
    {
        return $this->pipelineJobId.':'.($this->queue ?? static::QUEUE);
    }

    protected function run(PipelineJob $job): string
    {
        $build = $job->subject;

        return $build instanceof SignedBuild ? app(SigningService::class)->prepare($build) : 'SUBJECT_MISSING';
    }

    protected function failedPermanently(PipelineJob $job, Throwable $error): void
    {
        $build = $job->subject;
        if ($build instanceof SignedBuild) {
            app(SigningService::class)->failBuild($build, 'PREPARATION_FAILED', 'Profile provisioning failed repeatedly.');
        }
    }
}
