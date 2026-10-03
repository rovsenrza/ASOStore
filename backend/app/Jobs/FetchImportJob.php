<?php

namespace App\Jobs;

use App\Models\CatalogApp;
use App\Models\PipelineJob;
use App\Services\Imports\ImportService;
use App\Services\Imports\LinkFetcher;
use App\Services\Imports\LinkFetchFailed;
use Throwable;

/**
 * Downloads a customer's link import on the server, then hands the file to the same chunked
 * upload and inspection as an import from Files. A link that can never work (private address,
 * not an IPA, too large) ends the job with LINK_REJECTED and the reason in its payload; a network
 * hiccup is retried.
 */
class FetchImportJob extends PipelineQueueJob
{
    public const TYPE = 'FetchImportJob';

    public const QUEUE = 'files';

    public const REJECTED = 'LINK_REJECTED';

    public int $timeout = 1800;

    public static function idempotencyKey(CatalogApp $app): string
    {
        return 'import-fetch:'.$app->public_id;
    }

    protected function run(PipelineJob $job): string
    {
        $app = $job->subject;
        if (! $app instanceof CatalogApp || $app->imported_by_user_id === null) {
            return 'SUBJECT_MISSING';
        }
        if ($app->artifacts()->exists()) {
            return 'ALREADY_FETCHED';
        }

        $temporary = tempnam(sys_get_temp_dir(), 'import-');
        if ($temporary === false) {
            throw LinkFetchFailed::retryable('Сервер временно не может сохранить файл.');
        }

        try {
            $file = app(LinkFetcher::class)->fetch((string) $job->payload['url'], $temporary, ImportService::MAX_BYTES);
            app(ImportService::class)->ingest($app, $temporary, $file['filename'], $file['size_bytes'], $file['sha256']);

            return 'FETCHED';
        } catch (LinkFetchFailed $failure) {
            if (! $failure->permanent) {
                throw $failure;
            }
            $job->forceFill(['payload' => $job->payload + ['failure' => $failure->getMessage()]])->save();

            return self::REJECTED;
        } finally {
            @unlink($temporary);
        }
    }

    protected function failedPermanently(PipelineJob $job, Throwable $error): void
    {
        $message = $error instanceof LinkFetchFailed ? $error->getMessage() : 'Не удалось скачать файл по ссылке.';
        $job->forceFill(['payload' => $job->payload + ['failure' => $message]])->save();
    }
}
