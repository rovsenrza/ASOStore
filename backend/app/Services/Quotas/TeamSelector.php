<?php

namespace App\Services\Quotas;

use App\Enums\AppleTeamStatus;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Models\AppleTeam;
use App\Models\CatalogApp;
use App\Models\DeviceRegistration;
use App\Models\TeamAppEligibility;
use App\Models\TeamAssignment;
use App\Models\TeamQuota;
use App\Services\Apple\AppleIntegration;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\StateMachines\StateMachine;

/**
 * What happens when a team is out of slots (FULL_PLAN §6.2 steps 4–5, §12).
 * Only a team that is active, connected, inside a membership year, has free
 * slots for the family and is approved for the Storefront's bundle ID may be
 * proposed — and only as a PENDING assignment an admin must approve.
 * Otherwise the device is blocked with NO_ELIGIBLE_TEAM. Never loops, never
 * creates accounts.
 */
class TeamSelector
{
    public function __construct(
        private readonly StateMachine $states,
        private readonly AuditService $audit,
        private readonly AppleIntegration $apple,
    ) {}

    /**
     * Called inside QuotaService's transaction, with the exhausted quota row locked.
     */
    public function handleExhausted(DeviceRegistration $registration, TeamQuota $exhausted): string
    {
        $family = $registration->device_family;
        $candidate = $this->eligibleTeams($family->value, exclude: $registration->apple_team_id)[0] ?? null;
        $actor = Actor::system('quota');

        if ($candidate === null) {
            $this->move($registration, Status::NoEligibleTeam, 'NO_ELIGIBLE_TEAM', 'No other team is eligible and has free slots.');
            $this->audit->record('quota.no_eligible_team', $registration, after: [
                'team' => $exhausted->team->apple_team_id,
                'family' => $family->value,
            ], reason: 'Team exhausted and no eligible team with free slots exists; nothing was switched.', actor: $actor);

            return QuotaService::NO_ELIGIBLE_TEAM;
        }

        [$team, $remaining] = $candidate;
        $reason = sprintf(
            'Team %s has no free %s slots in its membership year. Team %s is active, approved for the Storefront bundle ID and has %d free slots.',
            $exhausted->team->apple_team_id, $family->value, $team->apple_team_id, $remaining,
        );

        $this->move($registration, Status::QuotaBlocked, QuotaService::AWAITING_TEAM_APPROVAL, $reason);
        $assignment = TeamAssignment::query()->firstOrCreate(
            ['device_id' => $registration->device_id, 'status' => 'PENDING'],
            ['blocked_registration_id' => $registration->id, 'apple_team_id' => $team->id, 'selection_reason' => $reason],
        );
        $this->audit->record('team.assignment.proposed', $assignment, after: [
            'device_id' => $registration->device->public_id,
            'from_team' => $exhausted->team->apple_team_id,
            'to_team' => $team->apple_team_id,
            'family' => $family->value,
        ], reason: $reason, actor: $actor);

        return QuotaService::AWAITING_TEAM_APPROVAL;
    }

    /**
     * Eligible teams with free slots for the family, most free slots first.
     *
     * @return list<array{0: AppleTeam, 1: int}>
     */
    public function eligibleTeams(string $family, ?int $exclude = null): array
    {
        $bundle = CatalogApp::query()->where('is_storefront', true)->latest('id')->first()?->publishedArtifact()->value('bundle_identifier');
        $candidates = [];

        $teams = AppleTeam::query()
            ->whereIn('status', [AppleTeamStatus::Active->value, AppleTeamStatus::Expiring->value])
            ->when($exclude !== null, fn ($query) => $query->whereKeyNot($exclude))
            ->with('activeCredential')
            ->orderBy('id')
            ->get();

        foreach ($teams as $team) {
            $year = $team->currentMembershipYear();
            if ($year === null || ! $this->apple->isConfigured($team)) {
                continue;
            }
            // The device must be able to install the Storefront from that team.
            if ($bundle !== null ? ! TeamAppEligibility::allows($team->id, $bundle) : ! TeamAppEligibility::query()->where(['apple_team_id' => $team->id, 'status' => 'APPROVED'])->exists()) {
                continue;
            }

            $quota = TeamQuota::query()->firstOrCreate(
                ['apple_team_id' => $team->id, 'membership_year_id' => $year->id, 'device_family' => $family],
                ['limit_count' => (int) config('storefront.apple.device_limit_per_family')],
            );
            $remaining = $quota->remaining();
            if ($remaining > 0) {
                $candidates[] = [$team, $remaining];
            }
        }

        usort($candidates, fn (array $a, array $b) => $b[1] <=> $a[1]);

        return $candidates;
    }

    private function move(DeviceRegistration $registration, Status $to, string $reason, string $message): void
    {
        if ($registration->status === $to) {
            $registration->forceFill(['status_reason' => $reason])->save();

            return;
        }

        $this->states->transition($registration, $to, $message, Actor::system('quota'), extra: ['status_reason' => $reason]);
    }
}
