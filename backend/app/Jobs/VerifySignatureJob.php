<?php

namespace App\Jobs;

use App\Models\PipelineJob;
use App\Models\SignedBuild;
use App\Services\Signing\SignatureVerifier;
use App\Services\Signing\SigningService;
use Throwable;

/**
 * Independently verifies a runner's signed build before it becomes DELIVERABLE (P6-BE-03).
 */
class VerifySignatureJob extends PipelineQueueJob
{
    public const TYPE = 'VerifySignatureJob';

    public const QUEUE = 'files';

    public int $timeout = 1800;

    protected function run(PipelineJob $job): string
    {
        $build = $job->subject;

        return $build instanceof SignedBuild ? app(SignatureVerifier::class)->verify($build) : 'SUBJECT_MISSING';
    }

    protected function failedPermanently(PipelineJob $job, Throwable $error): void
    {
        $build = $job->subject;
        if ($build instanceof SignedBuild) {
            app(SigningService::class)->failBuild($build, 'VERIFICATION_ERROR', 'Verification could not run.');
        }
    }
}
