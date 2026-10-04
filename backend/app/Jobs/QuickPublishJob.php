<?php

namespace App\Jobs;

use App\Models\AppArtifact;
use App\Models\PipelineJob;
use App\Services\Artifacts\QuickPublishService;
use Throwable;

/**
 * Takes one uploaded IPA all the way to the catalog (admin «Быстрая публикация»). It waits for
 * inspection and cleaning by releasing itself, so it never holds a worker while they run.
 */
class QuickPublishJob extends PipelineQueueJob
{
    public const TYPE = 'QuickPublishJob';

    public const QUEUE = 'files';

    /** Each wait is one queue attempt; a large file can take many minutes to inspect and clean. */
    public int $tries = 1000;

    public static function idempotencyKey(AppArtifact $artifact): string
    {
        return 'quick-publish:'.$artifact->public_id;
    }

    protected function run(PipelineJob $job): string
    {
        return app(QuickPublishService::class)->run($job);
    }

    protected function failedPermanently(PipelineJob $job, Throwable $error): void
    {
        app(QuickPublishService::class)->note($job, QuickPublishService::STAGE_FAILED, 'Внутренняя ошибка при публикации. Повторите задачу на странице «Задачи» или напишите разработчику.');
    }
}
