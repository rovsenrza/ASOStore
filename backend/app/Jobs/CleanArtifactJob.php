<?php

namespace App\Jobs;

use App\Models\AppArtifact;
use App\Models\PipelineJob;
use App\Services\Artifacts\ArtifactCleaningService;

/**
 * Makes a cleaned copy of one uploaded IPA with tools/ipa-cleaner.
 */
class CleanArtifactJob extends PipelineQueueJob
{
    public const TYPE = 'CleanArtifactJob';

    public const QUEUE = 'files';

    public int $timeout = 1800;

    protected function run(PipelineJob $job): string
    {
        $artifact = $job->subject;
        if (! $artifact instanceof AppArtifact) {
            return 'SUBJECT_MISSING';
        }

        return app(ArtifactCleaningService::class)->clean($artifact, $job);
    }
}
