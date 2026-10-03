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

    public function complete(Request $request, string $upload): JsonResponse
    {
        $session = $this->ownedUpload($request, $upload);
        $artifact = $this->uploads->complete($session)->refresh();
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
        if ($upload !== null && in_array($upload->status, ['OPEN', 'ASSEMBLING'], true) && $upload->expires_at->isFuture()) {
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
