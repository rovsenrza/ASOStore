<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Installation;
use App\Services\Artifacts\ArtifactFileCache;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Installations\InstallationService;
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
        $disk = Storage::disk('artifacts');
        $relative = $this->installations->downloadPath($model);
        $size = (int) $disk->size($relative);
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
