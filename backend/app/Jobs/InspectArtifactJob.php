<?php

namespace App\Jobs;

use App\Models\AppArtifact;
use App\Models\PipelineJob;
use App\Services\Artifacts\ArtifactInspectionService;

/**
 * Hashes and inspects one uploaded IPA (P5-BE-02).
 */
class InspectArtifactJob extends PipelineQueueJob
{
    public const TYPE = 'InspectArtifactJob';

    public const QUEUE = 'files';

    public int $timeout = 1800;

    public static function idempotencyKey(AppArtifact $artifact): string
    {
        return 'inspect:'.$artifact->public_id;
    }

    protected function run(PipelineJob $job): string
    {
        $artifact = $job->subject;
        if (! $artifact instanceof AppArtifact) {
            return 'SUBJECT_MISSING';
        }

        return app(ArtifactInspectionService::class)->inspect($artifact);
    }
}
