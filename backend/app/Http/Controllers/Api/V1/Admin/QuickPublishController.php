<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\QuickPublishJob;
use App\Models\PipelineJob;
use App\Models\UploadSession;
use App\Services\Artifacts\QuickPublishService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin «Быстрая публикация»: upload IPAs and have each one inspected, cleaned, matched to a
 * listing and published. Chunks go through the ordinary /admin/uploads/{upload}/chunks endpoint.
 */
class QuickPublishController extends Controller
{
    public function __construct(private readonly QuickPublishService $quick) {}

    public function index(): JsonResponse
    {
        $jobs = PipelineJob::query()->where('type', QuickPublishJob::TYPE)->latest('id')->limit(40)->get();

        return ApiResponse::ok($jobs->map(fn (PipelineJob $job) => $this->present($job))->all());
    }

    public function show(PipelineJob $job): JsonResponse
    {
        $this->assertQuickPublish($job);

        return ApiResponse::ok($this->present($job));
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'regex:/\.ipa$/i'],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.(int) config('storefront.quick_publish.max_bytes')],
            'sha256' => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{64}$/'],
            'declaration_accepted' => ['accepted'],
        ]);

        $upload = $this->quick->start($request->user(), $data['filename'], (int) $data['size_bytes'], $data['sha256'] ?? null, $request->ip());

        return ApiResponse::ok([
            'id' => $upload->public_id,
            'status' => $upload->status,
            'filename' => $upload->original_filename,
            'size_bytes' => $upload->expected_size,
            'chunk_size' => $upload->chunk_size,
            'chunk_count' => $upload->chunk_count,
            'expires_at' => $upload->expires_at->toIso8601ZuluString(),
        ], 201);
    }

    public function complete(Request $request, UploadSession $upload): JsonResponse
    {
        $data = $request->validate([
            'options' => ['nullable', 'array'],
            'options.remove_unknown_libraries' => ['boolean'],
            'options.allow_downgrade' => ['boolean'],
            'options.allow_other_sources' => ['boolean'],
        ]);

        $job = $this->quick->complete($upload, $request->user(), $data['options'] ?? [], $request->ip());

        return ApiResponse::ok($this->present($job->refresh()), 201);
    }

    /** Continues a held file under different options, e.g. «allow a downgrade». */
    public function resume(Request $request, PipelineJob $job): JsonResponse
    {
        $this->assertQuickPublish($job);
        $data = $request->validate([
            'options' => ['required', 'array'],
            'options.remove_unknown_libraries' => ['boolean'],
            'options.allow_downgrade' => ['boolean'],
            'options.allow_other_sources' => ['boolean'],
        ]);

        return ApiResponse::ok($this->present($this->quick->resume($job, $request->user(), $data['options'])->refresh()), 202);
    }

    private function assertQuickPublish(PipelineJob $job): void
    {
        if ($job->type !== QuickPublishJob::TYPE) {
            throw new ApiException(ErrorCode::NotFound);
        }
    }

    /** @return array<string, mixed> */
    private function present(PipelineJob $job): array
    {
        $payload = $job->payload ?? [];
        $stage = $payload['stage'] ?? 'INSPECTING';

        return [
            'id' => $job->public_id,
            'filename' => $payload['filename'] ?? null,
            'size_bytes' => $payload['size_bytes'] ?? null,
            'artifact_id' => $payload['artifact_id'] ?? null,
            'job_status' => $job->status->value,
            'stage' => $stage,
            'finished' => in_array($stage, QuickPublishService::TERMINAL, true),
            'message' => $payload['message'] ?? null,
            'hold_code' => $payload['hold_code'] ?? null,
            'options' => $payload['options'] ?? QuickPublishService::DEFAULT_OPTIONS,
            'cleaning' => $payload['cleaning'] ?? [],
            'steps' => $payload['steps'] ?? [],
            'result' => $payload['result'] ?? null,
            'created_at' => $job->created_at?->toIso8601ZuluString(),
            'finished_at' => $job->finished_at?->toIso8601ZuluString(),
        ];
    }
}
