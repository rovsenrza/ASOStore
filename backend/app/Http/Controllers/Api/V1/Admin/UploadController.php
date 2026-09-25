<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ErrorCode;
use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\InspectArtifactJob;
use App\Models\AppVersion;
use App\Models\CatalogApp;
use App\Models\PipelineJob;
use App\Models\UploadSession;
use App\Services\Artifacts\ChunkedUploadService;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UploadController extends Controller
{
    public function __construct(
        private readonly ChunkedUploadService $uploads,
        private readonly AuditService $audit,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'app_id' => ['required', 'string'],
            'app_version_id' => ['nullable', 'string'],
            'filename' => ['required', 'string', 'max:255', 'regex:/\.ipa$/i'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:5368709120'],
            'sha256' => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'source_type' => ['required', Rule::enum(SourceType::class)],
            'declaration_version' => ['required', 'string', 'max:32'],
            'declaration_accepted' => ['accepted'],
        ]);

        $app = CatalogApp::withTrashed()->where('public_id', strtolower($data['app_id']))->firstOrFail();
        $version = null;
        if (isset($data['app_version_id'])) {
            $version = AppVersion::query()->where('public_id', strtolower($data['app_version_id']))->firstOrFail();
            if ($version->app_id !== $app->id) {
                throw new ApiException(ErrorCode::ValidationFailed, details: ['app_version_id' => 'Версия относится к другому приложению.']);
            }
        }

        $chunkSize = UploadSession::CHUNK_SIZE;
        $upload = UploadSession::create([
            'app_id' => $app->id,
            'app_version_id' => $version?->id,
            'uploaded_by' => $request->user()->id,
            'original_filename' => basename($data['filename']),
            'expected_size' => $data['size_bytes'],
            'expected_sha256' => isset($data['sha256']) ? strtolower($data['sha256']) : null,
            'chunk_size' => $chunkSize,
            'chunk_count' => (int) ceil($data['size_bytes'] / $chunkSize),
            'source_type' => $data['source_type'],
            'declaration_version' => $data['declaration_version'],
            'declaration_accepted_at' => now(),
            'declaration_ip' => $request->ip(),
            'status' => 'OPEN',
            'expires_at' => now()->addDay(),
        ]);

        $this->audit->record('artifact.upload_started', $upload, after: [
            'app_id' => $app->public_id,
            'filename' => $upload->original_filename,
            'size_bytes' => $upload->expected_size,
            'chunk_count' => $upload->chunk_count,
        ]);

        return ApiResponse::ok($this->present($upload), 201);
    }

    public function show(UploadSession $upload): JsonResponse
    {
        return ApiResponse::ok($this->present($upload));
    }

    public function chunk(Request $request, UploadSession $upload, int $number): JsonResponse
    {
        $declaredHash = $request->header('X-Chunk-SHA256');
        if ($declaredHash !== null && preg_match('/^[a-fA-F0-9]{64}$/', $declaredHash) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['X-Chunk-SHA256' => 'Неверная контрольная сумма.']);
        }

        return ApiResponse::ok($this->uploads->storeChunk($upload, $number, $request->getContent(), $declaredHash));
    }

    public function complete(UploadSession $upload): JsonResponse
    {
        $artifact = $this->uploads->complete($upload)->refresh();
        $job = PipelineJob::query()->where('idempotency_key', InspectArtifactJob::idempotencyKey($artifact))->first();

        return ApiResponse::ok([
            'id' => $artifact->public_id,
            'status' => $artifact->status->value,
            'job_id' => $job?->public_id,
            'sha256' => $artifact->sha256,
            'size_bytes' => $artifact->size_bytes,
            'original_filename' => $artifact->original_filename,
        ], 201);
    }

    /** @return array<string, mixed> */
    private function present(UploadSession $upload): array
    {
        $received = $upload->chunks()->orderBy('number')->pluck('number')->all();

        return [
            'id' => $upload->public_id,
            'status' => $upload->status,
            'filename' => $upload->original_filename,
            'size_bytes' => $upload->expected_size,
            'chunk_size' => $upload->chunk_size,
            'chunk_count' => $upload->chunk_count,
            'received_chunks' => $received,
            'missing_chunks' => array_values(array_diff(range(0, $upload->chunk_count - 1), $received)),
            'expires_at' => $upload->expires_at->toIso8601ZuluString(),
            'artifact_id' => $upload->artifact?->public_id,
        ];
    }
}
