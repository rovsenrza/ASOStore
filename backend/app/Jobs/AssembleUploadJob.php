<?php

namespace App\Jobs;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\PipelineJob;
use App\Models\UploadSession;
use App\Services\Artifacts\ChunkedUploadService;
use Throwable;

/**
 * Joins the chunks of a big upload (a customer's multi-gigabyte import) into the stored IPA.
 * The request that completed the upload has long since answered: the app only waits 30 seconds,
 * assembling takes minutes. A bad file ends the upload as FAILED with the reason; storage trouble
 * is retried, and when the retries run out the upload ends as FAILED with a sentence the app shows.
 */
class AssembleUploadJob extends PipelineQueueJob
{
    public const TYPE = 'AssembleUploadJob';

    public const QUEUE = 'files';

    public int $timeout = 1800;

    public static function idempotencyKey(UploadSession $upload): string
    {
        return 'assemble:'.$upload->public_id;
    }

    protected function run(PipelineJob $job): string
    {
        $upload = $job->subject;
        if (! $upload instanceof UploadSession) {
            return 'SUBJECT_MISSING';
        }
        $upload->refresh();
        if ($upload->status === 'COMPLETED') {
            return 'ALREADY_ASSEMBLED';
        }
        if ($upload->status !== 'ASSEMBLING') {
            return 'NOT_ASSEMBLING';
        }

        try {
            app(ChunkedUploadService::class)->assemble($upload, background: true);

            return 'ASSEMBLED';
        } catch (ApiException $refused) {
            if ($refused->errorCode === ErrorCode::ServiceUnavailable) {
                throw $refused;
            }

            // The upload already says why it failed (corrupt, duplicate, a chunk lost).
            return $refused->errorCode->value;
        }
    }

    protected function failedPermanently(PipelineJob $job, Throwable $error): void
    {
        $upload = $job->subject;
        if ($upload instanceof UploadSession && $upload->fresh()?->status === 'ASSEMBLING') {
            app(ChunkedUploadService::class)->fail($upload, ChunkedUploadService::BACKGROUND_FAILURE);
        }
    }
}
