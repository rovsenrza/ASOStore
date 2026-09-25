<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PipelineJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppArtifact;
use App\Models\PipelineJob;
use App\Models\PipelineJobAttempt;
use App\Services\Audit\Actor;
use App\Services\Pipeline\PipelineJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operator view of pipeline jobs (FULL_PLAN §9, IMPLEMENTATION_PLAN P5-ADM-02).
 */
class JobController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(PipelineJobStatus::class)],
            'type' => ['nullable', 'string', 'max:64'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PipelineJob::query()->with('subject')->latest('id');
        if (filled($filters['status'] ?? null)) {
            $query->whereIn('status', $filters['status']);
        }
        if (filled($filters['type'] ?? null)) {
            $query->where('type', $filters['type']);
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($page, array_map(fn (PipelineJob $job) => self::present($job), $page->items()));
    }

    public function show(PipelineJob $job): JsonResponse
    {
        return ApiResponse::ok(self::present($job) + [
            'payload' => $job->payload,
            'attempts' => $job->attempts()->orderBy('attempt')->get()->map(fn (PipelineJobAttempt $attempt) => [
                'attempt' => $attempt->attempt,
                'worker' => $attempt->worker,
                'started_at' => $attempt->started_at?->toIso8601ZuluString(),
                'finished_at' => $attempt->finished_at?->toIso8601ZuluString(),
                'result_code' => $attempt->result_code,
                'error_class' => $attempt->error_class,
                'error_message' => $attempt->error_message_redacted,
            ])->all(),
        ]);
    }

    public function retry(Request $request, PipelineJob $job, PipelineJobService $jobs): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $jobs->retry($job, Actor::user($request->user()), $data['reason']);

        return ApiResponse::ok(self::present($job->refresh()), 202);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(PipelineJob $job): array
    {
        $subject = $job->subject;

        return [
            'id' => $job->public_id,
            'type' => $job->type,
            'status' => $job->status->value,
            'attempt' => $job->attempt,
            'max_attempts' => $job->max_attempts,
            'subject' => $subject instanceof AppArtifact ? ['type' => 'artifact', 'id' => $subject->public_id] : null,
            'correlation_id' => $job->correlation_id,
            'result_code' => $job->result_code,
            'error_class' => $job->error_class,
            'error_message' => $job->error_message_redacted,
            'started_at' => $job->started_at?->toIso8601ZuluString(),
            'finished_at' => $job->finished_at?->toIso8601ZuluString(),
            'created_at' => $job->created_at?->toIso8601ZuluString(),
        ];
    }
}
