<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PipelineJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PipelineJob;
use App\Models\Runner;
use App\Services\Audit\AuditService;
use App\Services\Signing\SigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signing runner health for operators (IMPLEMENTATION_PLAN P6-ADM-01).
 */
class RunnerController extends Controller
{
    public function index(): JsonResponse
    {
        $leases = PipelineJob::query()
            ->where('type', SigningService::RUNNER_JOB_TYPE)
            ->whereIn('status', [PipelineJobStatus::Leased->value, PipelineJobStatus::Running->value])
            ->get()
            ->groupBy('lease_owner');
        $queued = PipelineJob::query()->where('type', SigningService::RUNNER_JOB_TYPE)->where('status', PipelineJobStatus::Queued->value)->count();

        return ApiResponse::ok(Runner::query()->orderBy('name')->get()->map(fn (Runner $runner) => [
            'id' => $runner->public_id,
            'name' => $runner->name,
            'key_id' => $runner->key_id,
            'status' => $runner->status,
            'online' => $runner->isOnline(),
            'version' => $runner->version,
            'last_heartbeat_at' => $runner->last_heartbeat_at?->toIso8601ZuluString(),
            'identities' => array_map(fn (array $identity) => array_intersect_key($identity, array_flip(['sha1', 'team_identifier', 'common_name', 'expires_at'])), $runner->identities ?? []),
            'current_jobs' => ($leases[$runner->key_id] ?? collect())->map(fn (PipelineJob $job) => [
                'id' => $job->public_id,
                'lease_expires_at' => $job->lease_expires_at?->toIso8601ZuluString(),
            ])->values()->all(),
        ])->all(), meta: ['queued_signing_jobs' => $queued]);
    }

    public function update(Request $request, Runner $runner, AuditService $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:ACTIVE,DISABLED'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $before = $runner->status;
        $runner->forceFill(['status' => $data['status']])->save();
        $audit->record('runner.status_changed', $runner, before: ['status' => $before], after: ['status' => $runner->status], reason: $data['reason']);

        return ApiResponse::ok(['id' => $runner->public_id, 'status' => $runner->status]);
    }
}
