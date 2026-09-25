<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\TeamAssignment;
use App\Services\Quotas\TeamAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pending team assignments for blocked devices (FULL_PLAN §12, IMPLEMENTATION_PLAN P7-BE-03).
 */
class TeamAssignmentController extends Controller
{
    public function __construct(private readonly TeamAssignmentService $assignments) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', 'in:PENDING,APPROVED,REJECTED']])['status'] ?? 'PENDING';
        $rows = TeamAssignment::query()->with(['team', 'device.user', 'blockedRegistration.team', 'decider'])
            ->where('status', $status)->latest('id')->limit(200)->get();

        return ApiResponse::ok($rows->map(fn (TeamAssignment $row) => $this->present($row))->all());
    }

    public function approve(Request $request, TeamAssignment $assignment): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return ApiResponse::ok($this->present($this->assignments->approve($assignment, $request->user(), $reason)->load(['team', 'device.user', 'blockedRegistration.team', 'decider'])));
    }

    public function reject(Request $request, TeamAssignment $assignment): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:1000']])['reason'];

        return ApiResponse::ok($this->present($this->assignments->reject($assignment, $request->user(), $reason)->load(['team', 'device.user', 'blockedRegistration.team', 'decider'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TeamAssignment $row): array
    {
        return [
            'id' => $row->public_id,
            'status' => $row->status,
            'device' => ['id' => $row->device->public_id, 'udid_hint' => $row->device->maskedUdid(), 'family' => $row->device->device_family->value, 'owner' => $row->device->user->email],
            'from_team' => $row->blockedRegistration->team->apple_team_id,
            'to_team' => ['id' => $row->team->public_id, 'apple_team_id' => $row->team->apple_team_id, 'name' => $row->team->name],
            'selection_reason' => $row->selection_reason,
            'decided_by' => $row->decider?->email,
            'decision_reason' => $row->decision_reason,
            'decided_at' => $row->decided_at?->toIso8601ZuluString(),
            'created_at' => $row->created_at?->toIso8601ZuluString(),
        ];
    }
}
