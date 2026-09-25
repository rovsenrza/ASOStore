<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppleTeam;
use App\Models\TeamAppEligibility;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Which team may distribute which bundle ID, with evidence (IMPLEMENTATION_PLAN P7-BE-03).
 */
class TeamEligibilityController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $rows = TeamAppEligibility::query()->with(['team', 'approver'])->latest('id')
            ->when($request->query('bundle_identifier'), fn ($query, $bundle) => $query->where('bundle_identifier', $bundle))
            ->limit(500)->get();

        return ApiResponse::ok($rows->map(fn (TeamAppEligibility $row) => $this->present($row))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'team_id' => ['required', 'string'],
            'bundle_identifier' => ['required', 'string', 'max:155', 'regex:/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/'],
            'evidence' => ['required', 'string', 'max:2000'],
        ]);
        $team = AppleTeam::query()->where('public_id', strtolower($data['team_id']))->firstOrFail();

        $row = TeamAppEligibility::query()->updateOrCreate(
            ['apple_team_id' => $team->id, 'bundle_identifier' => $data['bundle_identifier']],
            ['evidence' => $data['evidence'], 'status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()],
        );
        $this->audit->record('team.eligibility.approved', $row, after: ['team' => $team->apple_team_id, 'bundle_identifier' => $row->bundle_identifier], reason: $data['evidence']);

        return ApiResponse::ok($this->present($row->load(['team', 'approver'])), 201);
    }

    public function revoke(Request $request, TeamAppEligibility $eligibility): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $eligibility->forceFill(['status' => 'REVOKED'])->save();
        $this->audit->record('team.eligibility.revoked', $eligibility, reason: $data['reason']);

        return ApiResponse::ok($this->present($eligibility->load(['team', 'approver'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TeamAppEligibility $row): array
    {
        return [
            'id' => $row->public_id,
            'team' => ['id' => $row->team->public_id, 'apple_team_id' => $row->team->apple_team_id, 'name' => $row->team->name],
            'bundle_identifier' => $row->bundle_identifier,
            'evidence' => $row->evidence,
            'status' => $row->status,
            'approved_by' => $row->approver?->email,
            'approved_at' => $row->approved_at->toIso8601ZuluString(),
        ];
    }
}
