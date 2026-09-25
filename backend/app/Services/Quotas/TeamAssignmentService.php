<?php

namespace App\Services\Quotas;

use App\Enums\DeviceRegistrationStatus as Status;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Jobs\RegisterDeviceJob;
use App\Models\DeviceRegistration;
use App\Models\TeamAssignment;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;

/**
 * Admin decisions on proposed team assignments (FULL_PLAN §12). Approval
 * registers the device with the proposed team through the normal,
 * quota-checked path; nothing proceeds before it.
 */
class TeamAssignmentService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TeamSelector $selector,
    ) {}

    public function approve(TeamAssignment $assignment, User $admin, string $reason): TeamAssignment
    {
        $registration = DB::transaction(function () use ($assignment, $admin, $reason) {
            $locked = $this->pending($assignment);
            $team = $locked->team;
            $device = $locked->device;

            // Re-check at decision time: the team may have filled up or lost eligibility.
            $stillEligible = collect($this->selector->eligibleTeams($device->device_family->value))
                ->contains(fn (array $candidate) => $candidate[0]->is($team));
            if (! $stillEligible) {
                throw new ApiException(ErrorCode::NoEligibleTeam, 'Команда больше не подходит: нет свободных мест или разрешения.');
            }

            $year = $team->currentMembershipYear() ?? throw new ApiException(ErrorCode::NoEligibleTeam);
            $registration = DeviceRegistration::query()->firstOrCreate(
                ['apple_team_id' => $team->id, 'udid_hash' => $device->udid_hash, 'membership_year_id' => $year->id],
                ['device_id' => $device->id, 'device_family' => $device->device_family, 'status' => Status::Enrolled],
            );

            $locked->forceFill([
                'status' => 'APPROVED',
                'decided_by' => $admin->id,
                'decision_reason' => $reason,
                'decided_at' => now(),
                'registration_id' => $registration->id,
            ])->save();
            $this->audit->record('team.assignment.approved', $locked, after: [
                'to_team' => $team->apple_team_id,
                'device_id' => $device->public_id,
                'registration_id' => $registration->public_id,
            ], reason: $reason, actor: Actor::user($admin));

            return $registration;
        });

        RegisterDeviceJob::dispatch($registration->id)->afterCommit();

        return $assignment->refresh();
    }

    public function reject(TeamAssignment $assignment, User $admin, string $reason): TeamAssignment
    {
        return DB::transaction(function () use ($assignment, $admin, $reason) {
            $locked = $this->pending($assignment);
            $locked->forceFill(['status' => 'REJECTED', 'decided_by' => $admin->id, 'decision_reason' => $reason, 'decided_at' => now()])->save();
            $locked->blockedRegistration->forceFill(['status_reason' => 'ASSIGNMENT_REJECTED'])->save();
            $this->audit->record('team.assignment.rejected', $locked, reason: $reason, actor: Actor::user($admin));

            return $locked;
        });
    }

    private function pending(TeamAssignment $assignment): TeamAssignment
    {
        $locked = TeamAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'PENDING') {
            throw new ApiException(ErrorCode::Conflict, 'Решение по этому назначению уже принято.', ['status' => $locked->status]);
        }

        return $locked;
    }
}
