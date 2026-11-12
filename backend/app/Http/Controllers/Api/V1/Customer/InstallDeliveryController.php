<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Installation;
use App\Models\SignedBuild;
use App\Services\Artifacts\ArtifactFileCache;
use App\Services\Artifacts\LocalArtifactFile;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Installations\InstallationService;
use App\Services\Signing\Delta\DeltaComposer;
use App\Services\Signing\Delta\DeltaCoreLocator;
use App\Services\Signing\Delta\DeltaException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What iOS itself fetches during an OTA install: the manifest (single-use
 * token) and the signed IPA (short-lived signed URL, HTTP Range supported).
 */
class InstallDeliveryController extends Controller
{
    public function __construct(
        private readonly InstallationService $installations,
        private readonly AuditService $audit,
    ) {}

    public function manifest(Request $request, string $token): Response
    {
        return response($this->installations->manifest($token, $request), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function download(Request $request, string $installation): SymfonyResponse
    {
        // Unbuffered by nginx, the response lasts as long as the phone takes to receive it.
        set_time_limit(0);
        $model = Installation::query()->where('public_id', strtolower($installation))->first();

        if (! $request->hasValidSignature()) {
            // Tampered or expired links are refused and logged (Phase 5 exit gate).
            Log::warning('install.download_rejected', ['installation' => $installation, 'ip' => $request->ip()]);
            if ($model !== null) {
                $this->audit->record('installation.download_rejected', $model, after: ['reason' => 'INVALID_SIGNATURE'], actor: Actor::system('downloads'));
            }

            throw new ApiException(ErrorCode::Forbidden, 'Ссылка на загрузку недействительна или устарела.');
        }
        if ($model === null) {
            throw new ApiException(ErrorCode::NotFound);
        }

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('signed');
        $relative = $this->installations->downloadPath($model);
        // The size recorded with the build saves a HEAD request to object storage. Without one, a
        // missing object or a throttled (429) bucket is a temporary failure for iOS to retry, not a 500.
        $size = (int) $model->signedBuild?->deliveredBytes();
        if ($size <= 0 && $model->signedBuild?->isDelta()) {
            // A pack with no composed size has not been verified: nothing to send yet.
            return new SymfonyResponse('', 503, ['Retry-After' => '30', 'Cache-Control' => 'private, no-store']);
        }
        if ($size <= 0) {
            try {
                $size = (int) $disk->size($relative);
            } catch (\Throwable $exception) {
                Log::warning('install.download_size_unavailable', ['installation' => $model->public_id, 'error' => $exception->getMessage()]);

                return new SymfonyResponse('', 503, ['Retry-After' => '30', 'Cache-Control' => 'private, no-store']);
            }
        }
        $range = (string) $request->header('Range');
        [$start, $end] = self::range($range, $size);

        if ($size === 0 || $start > $end || $start >= $size) {
            return new SymfonyResponse('', 416, [
                'Content-Range' => "bytes */{$size}",
                'Accept-Ranges' => 'bytes',
                'Cache-Control' => 'private, no-store',
            ]);
        }

        if ($start === 0) {
            $this->installations->downloadStarted($model, $request);
        }
        if ($end >= $size - 1) {
            // Runs after the body has been sent; an aborted transfer does not count.
            app()->terminating(function () use ($model, $request) {
                if (connection_status() === CONNECTION_NORMAL) {
                    $this->installations->downloadCompleted($model, $request);
                }
            });
        }

        $filename = $model->app->slug.'.ipa';
        if ($model->signedBuild->isDelta()) {
            return $this->streamDelta($request, $model->signedBuild, $disk, $relative, $size, $start, $end, $filename);
        }
        $cached = app(ArtifactFileCache::class)->get($disk, $relative, (string) $model->signedBuild->sha256, $size);
        if ($cached === null && ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return $this->streamRemote($request, $disk, $relative, $size, $start, $end, $filename);
        }

        $response = new BinaryFileResponse($cached ?? $disk->path($relative), 200, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ], false);
        $response->setContentDisposition('attachment', $filename);

        return $response;
    }

    /**
     * A delta build: the IPA is composed while it is sent, from the artifact's original and the pack of
     * entries re-signing changed (docs/adr/DELTA_BUILDS.md), plus the artifact's shared core for a delta-v2
     * pack (docs/adr/DELTA_V3.md). Same Range behaviour as a stored file.
     */
    private function streamDelta(Request $request, SignedBuild $build, FilesystemAdapter $signed, string $relative, int $size, int $start, int $end, string $filename): SymfonyResponse
    {
        $artifact = $build->artifact;
        app(\App\Services\Artifacts\ColdStorage::class)->ensureLocal($artifact);
        $base = Storage::disk($artifact->storage_disk);
        if (! $base->getAdapter() instanceof LocalFilesystemAdapter || ! $base->exists($artifact->storage_path)) {
            Log::error('install.delta_base_missing', ['build' => $build->public_id]);

            return new SymfonyResponse('', 503, ['Retry-After' => '30', 'Cache-Control' => 'private, no-store']);
        }

        $packPath = app(ArtifactFileCache::class)->get($signed, $relative, (string) $build->sha256, (int) $build->size_bytes);
        $copy = null;
        try {
            if ($packPath === null) {
                $copy = LocalArtifactFile::open($signed, $relative);
                $packPath = $copy->path;
            }
            $basePath = $base->path($artifact->storage_path);
            // A delta-v2 pack's segmented entries read from the artifact's shared core.
            $coreSha256 = DeltaComposer::requiredCore($packPath);
            $corePath = $coreSha256 === null ? null : app(DeltaCoreLocator::class)->localPath($coreSha256);
            if ($coreSha256 !== null && ($corePath === null || ! is_file($corePath))) {
                $copy?->release();
                Log::error('install.delta_core_missing', ['build' => $build->public_id, 'core' => $coreSha256]);

                return new SymfonyResponse('', 503, ['Retry-After' => '30', 'Cache-Control' => 'private, no-store']);
            }
            $plan = DeltaComposer::plan($basePath, $packPath, corePath: $corePath);
            if ($plan->size !== $size) {
                throw new DeltaException("Composed {$plan->size} bytes, the build says {$size}.");
            }
        } catch (\Throwable $exception) {
            $copy?->release();
            Log::error('install.delta_unavailable', ['build' => $build->public_id, 'error' => $exception->getMessage()]);

            return new SymfonyResponse('', 503, ['Retry-After' => '30', 'Cache-Control' => 'private, no-store']);
        }

        $partial = $start > 0 || $end < $size - 1;
        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) ($end - $start + 1),
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename),
        ];
        if ($partial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }
        $status = $partial ? 206 : 200;
        if ($request->isMethod('HEAD')) {
            $copy?->release();

            return new SymfonyResponse('', $status, $headers);
        }

        return new StreamedResponse(function () use ($plan, $basePath, $packPath, $corePath, $start, $end, $copy) {
            // A client that hangs up would otherwise abort PHP mid-echo and skip `finally`, leaving the
            // temporary pack copy behind; keep running so the alive check ends the loop and we clean up.
            ignore_user_abort(true);
            if ($copy !== null) {
                register_shutdown_function(fn () => $copy->release());
            }
            try {
                $plan->stream($basePath, $packPath, $start, $end, function (string $chunk) {
                    echo $chunk;
                    flush();
                }, fn () => connection_status() === CONNECTION_NORMAL, $corePath);
            } finally {
                $copy?->release();
            }
        }, $status, $headers);
    }

    /**
     * Relays the IPA from object storage with the same Range behaviour as a
     * local file: 206 with Content-Range for a partial request, 416 when the
     * range cannot be satisfied. Only the requested bytes are fetched.
     */
    private function streamRemote(Request $request, FilesystemAdapter $disk, string $relative, int $size, int $start, int $end, string $filename): SymfonyResponse
    {
        $headers = [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename),
        ];
        if ($size === 0 || $start > $end || $start >= $size) {
            return new SymfonyResponse('', 416, $headers + ['Content-Range' => "bytes */{$size}"]);
        }

        $partial = $start > 0 || $end < $size - 1;
        $headers['Content-Length'] = (string) ($end - $start + 1);
        if ($partial) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }
        $status = $partial ? 206 : 200;
        if ($request->isMethod('HEAD')) {
            return new SymfonyResponse('', $status, $headers);
        }

        /** @var AwsS3V3Adapter $disk */
        $object = $disk->getClient()->getObject([
            'Bucket' => $disk->getConfig()['bucket'],
            'Key' => $disk->path($relative),
            'Range' => "bytes={$start}-{$end}",
        ]);

        return new StreamedResponse(function () use ($object) {
            $body = $object['Body'];
            while (! $body->eof() && connection_status() === CONNECTION_NORMAL) {
                echo $body->read(1024 * 1024);
                flush();
            }
        }, $status, $headers);
    }

    /**
     * @return array{0: int, 1: int} First and last byte the request asks for.
     */
    private static function range(string $header, int $size): array
    {
        if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $match) !== 1) {
            return [0, $size - 1];
        }
        if ($match[1] === '') {
            return [max(0, $size - (int) $match[2]), $size - 1];
        }

        return [(int) $match[1], $match[2] === '' ? $size - 1 : min((int) $match[2], $size - 1)];
    }
}
