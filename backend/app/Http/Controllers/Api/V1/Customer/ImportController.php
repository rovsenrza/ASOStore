<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\ArtifactStatus;
use App\Enums\ErrorCode;
use App\Enums\InstallationStatus;
use App\Enums\PipelineJobStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\FetchImportJob;
use App\Jobs\InspectArtifactJob;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\PipelineJob;
use App\Models\UploadSession;
use App\Services\Artifacts\ChunkedUploadService;
use App\Services\Devices\CurrentDevice;
use App\Services\Imports\ImportService;
use App\Services\Installations\InstallationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer self-import of an IPA from Files (chunked upload) or from a link (downloaded on the server). Each import is a hidden app owned by
 * the customer; only its owner can read, install or delete it. The upload, inspection and signing
 * reuse the existing pipeline.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly ImportService $imports,
        private readonly ChunkedUploadService $uploads,
        private readonly InstallationService $installations,
        private readonly CurrentDevice $devices,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'regex:/\.ipa$/i'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.ImportService::MAX_BYTES],
            'sha256' => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'declaration_accepted' => ['accepted'],
        ]);

        $upload = $this->imports->start($request->user(), $data['filename'], (int) $data['size_bytes'], $data['sha256'] ?? null, $request->ip());

        return ApiResponse::ok($this->presentUpload($upload), 201);
    }

    /** Import from a link: the server downloads the file, then inspects it like one from Files. */
    public function link(Request $request): JsonResponse
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'declaration_accepted' => ['accepted'],
        ]);

        $app = $this->imports->startFromLink($request->user(), $data['url'], $request->ip());

        return ApiResponse::ok($this->presentImport($app->load(['artifacts' => fn ($query) => $query->latest('id')->limit(1)])), 202);
    }

    public function chunk(Request $request, string $upload, int $number): JsonResponse
    {
        $session = $this->ownedUpload($request, $upload);
        $declaredHash = $request->header('X-Chunk-SHA256');
        if ($declaredHash !== null && preg_match('/^[a-fA-F0-9]{64}$/', $declaredHash) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['X-Chunk-SHA256' => 'Неверная контрольная сумма.']);
        }

        return ApiResponse::ok($this->uploads->storeChunk($session, $number, $request->getContent(), $declaredHash));
    }

    /**
     * A chunk from the app's background upload session, authenticated by the upload's own token
     * (X-Upload-Token) instead of the customer's access token. The app may be suspended or closed
     * when the last chunk lands, so the server completes the upload itself then.
     */
    public function chunkWithToken(Request $request, string $upload, int $number): JsonResponse
    {
        $session = UploadSession::query()->where('public_id', strtolower($upload))->with('app')->first();
        $token = (string) $request->header('X-Upload-Token', '');
        if ($session === null || $session->app === null || $session->app->imported_by_user_id !== $session->uploaded_by
            || $token === '' || ! hash_equals($session->clientToken(), $token)) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $declaredHash = $request->header('X-Chunk-SHA256');
        if ($declaredHash !== null && preg_match('/^[a-fA-F0-9]{64}$/', $declaredHash) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['X-Chunk-SHA256' => 'Неверная контрольная сумма.']);
        }

        $stored = $this->uploads->storeChunk($session, $number, $request->getContent(), $declaredHash);
        $this->completeWhenAllReceived($session);

        return ApiResponse::ok($stored);
    }

    private function completeWhenAllReceived(UploadSession $session): void
    {
        $session->refresh();
        if ($session->status !== 'OPEN' || $session->chunks()->count() < $session->chunk_count) {
            return;
        }
        try {
            $this->uploads->completeInBackground($session);
        } catch (ApiException $exception) {
            // Sizes do not add up: the app's own complete call reports it and re-sends what is missing.
            report($exception);
        }
    }

    public function complete(Request $request, string $upload): JsonResponse
    {
        $session = $this->ownedUpload($request, $upload);
        // Already assembling (the last background chunk started it): answer like a big upload does.
        if ($session->status === 'ASSEMBLING' || $this->uploads->assemblesInBackground($session)) {
            // A multi-gigabyte file takes minutes to assemble and the app waits 30 seconds: answer now,
            // the import shows as uploading until the job has stored it (and again on a repeated call).
            $session = $this->uploads->completeInBackground($session);
            if ($session->status !== 'COMPLETED') {
                return ApiResponse::ok([
                    'import_id' => $session->app->public_id,
                    'status' => 'ASSEMBLING',
                    'job_id' => null,
                    'sha256' => $session->expected_sha256,
                    'size_bytes' => $session->expected_size,
                ], 202);
            }
            $artifact = $session->artifact()->firstOrFail();
        } else {
            $artifact = $this->uploads->complete($session)->refresh();
        }
        $job = PipelineJob::query()->where('idempotency_key', InspectArtifactJob::idempotencyKey($artifact))->first();

        return ApiResponse::ok([
            'import_id' => $session->app->public_id,
            'status' => $artifact->status->value,
            'job_id' => $job?->public_id,
            'sha256' => $artifact->sha256,
            'size_bytes' => $artifact->size_bytes,
        ], 201);
    }

    /** The customer's imports, newest first, with the latest artifact's state. */
    public function index(Request $request): JsonResponse
    {
        $apps = CatalogApp::query()
            ->where('imported_by_user_id', $request->user()->id)
            ->with(['artifacts' => fn ($query) => $query->latest('id')->limit(1)])
            ->latest('id')
            ->get();

        return ApiResponse::ok($apps->map(fn (CatalogApp $app) => $this->presentImport($app))->all());
    }

    /** Make an inspected import installable (approve + publish for the owner), then prepare it. */
    public function install(Request $request, string $import): JsonResponse
    {
        $app = $this->ownedImport($request, $import);
        $this->imports->finalize($app, $request->user());
        $installation = $this->installations->prepare($request->user(), $this->device($request), $app->refresh());

        return ApiResponse::ok(
            $this->installations->present($installation->refresh()),
            $installation->status === InstallationStatus::Preparing ? 202 : 200,
        );
    }

    /** Deletes an import and frees its storage; its installs end. */
    public function destroy(Request $request, string $import): JsonResponse
    {
        $this->imports->delete($this->ownedImport($request, $import), $request->user());

        return ApiResponse::ok(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentImport(CatalogApp $app): array
    {
        $artifact = $app->artifacts->first();
        [$status, $failure] = $artifact === null ? $this->pendingState($app) : [$artifact->status->value, $artifact->status_reason];
        // Prefer the name read from the IPA once it has been inspected; the app keeps the
        // filename-derived placeholder until then (and permanently once installed).
        $inspected = $artifact?->inspection['bundle']['name'] ?? null;
        $name = is_string($inspected) && trim($inspected) !== '' ? $inspected : $app->name;

        return [
            'id' => $app->public_id,
            'name' => $name,
            'status' => $status,
            'version' => $artifact?->version,
            'size_bytes' => $artifact?->size_bytes,
            // Inspected and waiting for the owner's first install (which approves it), or published.
            'installable' => in_array($artifact?->status, [ArtifactStatus::ProvenanceReview, ArtifactStatus::Published], true),
            'failure_reason' => $failure,
            'created_at' => $app->created_at?->toIso8601ZuluString(),
        ] + ($status === 'UPLOADING' ? $this->uploadProgress($app) : []);
    }

    /**
     * How far an upload from Files got, and whether chunks are still arriving: an iOS background
     * session keeps sending them while the app is closed, so the row is not necessarily abandoned.
     *
     * @return array{upload_progress: float, upload_active: bool}
     */
    private function uploadProgress(CatalogApp $app): array
    {
        $upload = UploadSession::query()->where('app_id', $app->id)->latest('id')->first();
        $received = $upload?->chunks()->count() ?? 0;
        $latest = $upload?->chunks()->max('created_at');

        return [
            'upload_progress' => $upload !== null && $upload->chunk_count > 0 ? round($received / $upload->chunk_count, 3) : 0.0,
            'upload_active' => $latest !== null && now()->diffInSeconds($latest, true) < 120,
        ];
    }

    /**
     * The state of an import that has no file yet: a link still downloading (or that failed),
     * or an upload from Files still in progress (or abandoned).
     *
     * @return array{0: string, 1: ?string}
     */
    private function pendingState(CatalogApp $app): array
    {
        $fetch = $app->pipelineJobs()->where('type', FetchImportJob::TYPE)->latest('id')->first();
        if ($fetch !== null) {
            $failure = $fetch->payload['failure'] ?? null;
            if ($failure !== null || $fetch->status === PipelineJobStatus::FailedPermanent) {
                return ['DOWNLOAD_FAILED', $failure ?? 'Не удалось скачать файл по ссылке.'];
            }

            return ['DOWNLOADING', null];
        }

        $upload = UploadSession::query()->where('app_id', $app->id)->latest('id')->first();
        if ($upload !== null && $upload->status === 'ASSEMBLING') {
            // Every chunk is in; the file is being put together (apps poll while UPLOADED).
            return ['UPLOADED', null];
        }
        if ($upload !== null && $upload->status === 'OPEN' && $upload->expires_at->isFuture()) {
            return ['UPLOADING', null];
        }

        return ['UPLOAD_FAILED', $upload !== null && $upload->failure_reason !== null ? $upload->failure_reason : 'Загрузка не завершилась.'];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentUpload(UploadSession $upload): array
    {
        $received = $upload->chunks()->orderBy('number')->pluck('number')->all();

        return [
            'id' => $upload->public_id,
            'import_id' => $upload->app->public_id,
            'status' => $upload->status,
            'chunk_size' => $upload->chunk_size,
            'chunk_count' => $upload->chunk_count,
            'received_chunks' => $received,
            'missing_chunks' => array_values(array_diff(range(0, $upload->chunk_count - 1), $received)),
            'expires_at' => $upload->expires_at->toIso8601ZuluString(),
            'upload_token' => $upload->clientToken(),
        ];
    }

    private function ownedUpload(Request $request, string $publicId): UploadSession
    {
        $upload = UploadSession::query()->where('public_id', strtolower($publicId))->with('app')->firstOrFail();
        if ($upload->uploaded_by !== $request->user()->id || $upload->app?->imported_by_user_id !== $request->user()->id) {
            throw new ApiException(ErrorCode::NotFound);
        }

        return $upload;
    }

    private function ownedImport(Request $request, string $publicId): CatalogApp
    {
        return CatalogApp::query()
            ->where('public_id', strtolower($publicId))
            ->where('imported_by_user_id', $request->user()->id)
            ->firstOrFail();
    }

    private function device(Request $request): Device
    {
        return $this->devices->resolve($request) ?? throw new ApiException(ErrorCode::DeviceNotEligible);
    }
}
