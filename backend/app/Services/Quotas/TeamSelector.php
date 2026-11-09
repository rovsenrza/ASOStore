<?php

namespace App\Services\Quotas;

use App\Enums\AppleTeamStatus;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Jobs\RegisterDeviceJob;
use App\Models\AppleTeam;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\QuotaReservation;
use App\Models\Subscription;
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
 * selected automatically when a distinct, published Ru App Store variant is
 * assigned to it. Legacy teams without a variant still require admin approval.
 * Never creates Apple accounts or assigns an unconfigured variant.
 */
class TeamSelector
{
    public function __construct(
        private readonly StateMachine $states,
        private readonly AuditService $audit,
        private readonly AppleIntegration $apple,
    ) {}

    /**
     * The quota rows handleExhausted may switch the registration to. QuotaService locks them
     * together with the exhausted row in ascending id order before calling it, so two callers
     * moving devices between the same teams can never lock two quota rows in opposite orders.
     *
     * @return list<int>
     */
    public function switchQuotaIds(DeviceRegistration $registration): array
    {
        $family = $registration->device_family->value;

        return array_map(fn (AppleTeam $team) => (int) TeamQuota::query()
            ->where(['apple_team_id' => $team->id, 'membership_year_id' => $team->currentMembershipYear()->id, 'device_family' => $family])
            ->value('id'), $this->automaticTeams($family, exclude: $registration->apple_team_id));
    }

    /**
     * Called inside QuotaService's transaction, with the exhausted quota row and the rows from
     * switchQuotaIds ($lockedQuotaIds) already locked. A team that became a candidate since is
     * skipped: locking its row now could invert the order.
     *
     * @param  list<int>|null  $lockedQuotaIds
     */
    public function handleExhausted(DeviceRegistration $registration, TeamQuota $exhausted, ?array $lockedQuotaIds = null): string
    {
        $family = $registration->device_family;
        $previous = TeamAssignment::query()
            ->where('blocked_registration_id', $registration->id)
            ->where('status', 'APPROVED')
            ->whereNotNull('registration_id')
            ->first();
        if ($previous !== null) {
            return QuotaService::SWITCHED;
        }

        foreach ($this->automaticTeams($family->value, exclude: $registration->apple_team_id) as $team) {
            $year = $team->currentMembershipYear();
            $where = ['apple_team_id' => $team->id, 'membership_year_id' => $year->id, 'device_family' => $family->value];
            if ($lockedQuotaIds !== null && ! in_array((int) TeamQuota::query()->where($where)->value('id'), $lockedQuotaIds, true)) {
                continue;
            }
            $quota = TeamQuota::query()->where($where)->lockForUpdate()->firstOrFail();
            if ($quota->remaining() <= 0) {
                continue;
            }

            $next = DeviceRegistration::query()->firstOrCreate(
                ['apple_team_id' => $team->id, 'udid_hash' => $registration->udid_hash, 'membership_year_id' => $year->id],
                ['device_id' => $registration->device_id, 'device_family' => $family, 'status' => Status::Enrolled],
            );
            if (! in_array($next->status->value, DeviceRegistration::CONSUMING_STATUSES, true)) {
                QuotaReservation::create([
                    'team_quota_id' => $quota->id,
                    'device_registration_id' => $next->id,
                    'expires_at' => now()->addMinutes(QuotaReservation::TTL_MINUTES),
                ]);
                $this->states->transition($next, Status::ApplePending, actor: Actor::system('quota'));
            }

            $reason = sprintf('Team %s is full; assigned %s with Ru App Store bundle %s.',
                $exhausted->team->apple_team_id, $team->apple_team_id,
                $team->storefrontApp->publishedArtifact->bundle_identifier);
            $this->move($registration, Status::QuotaBlocked, 'AUTO_SWITCHED', $reason);
            $assignment = TeamAssignment::create([
                'device_id' => $registration->device_id,
                'blocked_registration_id' => $registration->id,
                'apple_team_id' => $team->id,
                'status' => 'APPROVED',
                'selection_reason' => $reason,
                'decision_reason' => 'Automatic assignment to a configured team with capacity.',
                'decided_at' => now(),
                'registration_id' => $next->id,
            ]);
            $this->audit->record('team.assignment.auto_approved', $assignment, after: [
                'from_team' => $exhausted->team->apple_team_id,
                'to_team' => $team->apple_team_id,
                'bundle_identifier' => $team->storefrontApp->publishedArtifact->bundle_identifier,
                'device_id' => $registration->device->public_id,
            ], reason: $reason, actor: Actor::system('quota'));
            RegisterDeviceJob::dispatch($next->id)->afterCommit();

            return QuotaService::SWITCHED;
        }

        // Once per-team variants are configured, a legacy approval must not
        // place a device on a team whose Ru App Store build is missing.
        $candidate = AppleTeam::query()->whereNotNull('storefront_app_id')->exists()
            ? null
            : ($this->eligibleTeams($family->value, exclude: $registration->apple_team_id)[0] ?? null);
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
            'Team %s has no free %s slots in its membership year. Team %s is active, approved for the Ru App Store bundle ID and has %d free slots.',
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
     * The team for a new device: of every active, connected team with free slots
     * for the family, the one with the fewest devices this membership year (the
     * primary first on a tie, then the oldest team). Null when no team has a free
     * slot; the caller then uses the primary team. Warm-up teams (routing_rule) are offered
     * only for $device when it qualifies; with no free warm-up slot routing is unchanged.
     */
    public function instantTeam(string $family, ?int $exclude = null, ?Device $device = null): ?AppleTeam
    {
        $candidates = $this->automaticTeams($family, exclude: $exclude ?? 0, for: $device);

        // The primary team counts too, even without its own Ru App Store variant.
        $primary = AppleTeam::primary();
        if ($primary !== null && $primary->id !== $exclude
            && ! in_array($primary->id, array_map(fn (AppleTeam $team) => $team->id, $candidates), true)
            && in_array($primary->status, [AppleTeamStatus::Active, AppleTeamStatus::Expiring], true)
            && $this->apple->isConfigured($primary) && ($year = $primary->currentMembershipYear()) !== null) {
            $quota = TeamQuota::query()->firstOrCreate(
                ['apple_team_id' => $primary->id, 'membership_year_id' => $year->id, 'device_family' => $family],
                ['limit_count' => (int) config('storefront.apple.device_limit_per_family')],
            );
            if ($quota->remaining() > 0) {
                $candidates[] = $primary;
            }
        }
        usort($candidates, fn (AppleTeam $a, AppleTeam $b) => [! $a->is_primary, $a->id] <=> [! $b->is_primary, $b->id]);

        $least = null;
        $leastCount = null;
        foreach ($candidates as $team) {
            $count = $this->devicesThisYear($team);
            if ($least === null || $count < $leastCount) {
                [$least, $leastCount] = [$team, $count];
            }
        }

        return $least;
    }

    /**
     * Apple counts iPhones, iPads and iPods together as iOS devices. Apple's own
     * count also has devices added outside the Storefront.
     */
    private function devicesThisYear(AppleTeam $team): int
    {
        $quotas = TeamQuota::query()
            ->where(['apple_team_id' => $team->id, 'membership_year_id' => $team->currentMembershipYear()?->id])
            ->get();
        $ours = $quotas->sum(fn (TeamQuota $quota) => $quota->registeredCount() + $quota->reservedCount());

        return max($ours, (int) $quotas->sum('apple_registered_count'));
    }

    /**
     * Teams with a configured variant and free slots for the family, in creation order.
     *
     * @return list<AppleTeam>
     */
    private function automaticTeams(string $family, int $exclude, ?Device $for = null): array
    {
        $newMonthly = null;
        $teams = AppleTeam::query()
            ->whereIn('status', [AppleTeamStatus::Active->value, AppleTeamStatus::Expiring->value])
            ->whereKeyNot($exclude)
            ->whereNotNull('storefront_app_id')
            ->with(['activeCredential', 'storefrontApp.publishedArtifact'])
            ->orderBy('id')
            ->get();
        $ready = [];
        foreach ($teams as $team) {
            if ($team->routing_rule === AppleTeam::RULE_NEW_MONTHLY
                && ! ($newMonthly ??= $for !== null && self::isNewMonthlyCustomer($for))) {
                continue;
            }
            $year = $team->currentMembershipYear();
            $artifact = $team->storefrontApp?->publishedArtifact;
            if ($year === null || $artifact === null || ! $this->apple->isConfigured($team)
                || ! TeamAppEligibility::allows($team->id, $artifact->signingBundleIdentifier())) {
                continue;
            }
            // A second listing with the same Bundle ID is not a distinct app
            // variant, even if it belongs to a different team record.
            if (AppleTeam::query()->whereKeyNot($team->id)
                ->whereHas('storefrontApp.publishedArtifact', fn ($query) => $query->where('bundle_identifier', $artifact->bundle_identifier))
                ->exists()) {
                continue;
            }
            $quota = TeamQuota::query()->firstOrCreate(
                ['apple_team_id' => $team->id, 'membership_year_id' => $year->id, 'device_family' => $family],
                ['limit_count' => (int) config('storefront.apple.device_limit_per_family')],
            );
            if ($quota->remaining() > 0) {
                $ready[] = $team;
            }
        }

        return $ready;
    }

    /**
     * Eligible teams with free slots for the family, most free slots first.
     *
     * @return list<array{0: AppleTeam, 1: int}>
     */
    public function eligibleTeams(string $family, ?int $exclude = null): array
    {
        $storefront = AppleTeam::primary()?->storefrontApp;
        if ($storefront === null && CatalogApp::query()->where('is_storefront', true)->count() === 1) {
            $storefront = CatalogApp::query()->where('is_storefront', true)->first();
        }
        $bundle = $storefront?->publishedArtifact()->value('bundle_identifier');
        $candidates = [];

        $teams = AppleTeam::query()
            ->whereIn('status', [AppleTeamStatus::Active->value, AppleTeamStatus::Expiring->value])
            ->whereNull('routing_rule')
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

    /**
     * A new customer on a one-month plan: this is the first device of the account (no other
     * device was ever registered with Apple) and the active access spans at most 35 days.
     */
    public static function isNewMonthlyCustomer(Device $device): bool
    {
        if ($device->user_id === null) {
            return false;
        }
        $otherDevice = DeviceRegistration::query()
            ->where('udid_hash', '!=', $device->udid_hash)
            ->whereHas('device', fn ($query) => $query->where('user_id', $device->user_id))
            ->exists();
        if ($otherDevice) {
            return false;
        }
        $subscriptions = Subscription::query()->where('user_id', $device->user_id)->where('status', 'ACTIVE')->get(['starts_at', 'ends_at']);
        if ($subscriptions->isEmpty() || $subscriptions->contains(fn (Subscription $s) => $s->ends_at === null)) {
            return false;
        }
        $end = $subscriptions->max('ends_at');

        return $end->isFuture() && $subscriptions->min('starts_at')->diffInDays($end) <= 35;
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
