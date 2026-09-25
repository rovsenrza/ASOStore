<?php

namespace App\Services\Quotas;

use App\Enums\DeviceRegistrationStatus as Status;
use App\Models\AppleTeam;
use App\Models\DeviceRegistration;
use App\Models\QuotaReservation;
use App\Models\TeamQuota;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Transactional device-slot reservations (FULL_PLAN §6.2, IMPLEMENTATION_PLAN P7-BE-02).
 *
 * Every reservation locks the (team, year, family) quota row with SELECT … FOR
 * UPDATE, so concurrent registrations can never take more slots than exist.
 * When a team is exhausted nothing switches automatically: TeamSelector either
 * proposes an eligible team for admin approval or blocks with NO_ELIGIBLE_TEAM.
 */
class QuotaService
{
    public const RESERVED = 'RESERVED';

    public const AWAITING_TEAM_APPROVAL = 'AWAITING_TEAM_APPROVAL';

    public const NO_ELIGIBLE_TEAM = 'NO_ELIGIBLE_TEAM';

    public const ALREADY_HELD = 'ALREADY_HELD';

    public function __construct(
        private readonly StateMachine $states,
        private readonly AuditService $audit,
        private readonly TeamSelector $selector,
    ) {}

    public function quotaFor(DeviceRegistration $registration): TeamQuota
    {
        return TeamQuota::query()->firstOrCreate([
            'apple_team_id' => $registration->apple_team_id,
            'membership_year_id' => $registration->membership_year_id,
            'device_family' => $registration->device_family->value,
        ], ['limit_count' => (int) config('storefront.apple.device_limit_per_family')]);
    }

    /**
     * Reserves a slot and moves the registration to APPLE_PENDING, or blocks it.
     *
     * @return string One of the class constants.
     */
    public function reserve(DeviceRegistration $registration): string
    {
        $quotaId = $this->quotaFor($registration)->id;

        return DB::transaction(function () use ($registration, $quotaId) {
            $quota = TeamQuota::query()->whereKey($quotaId)->lockForUpdate()->firstOrFail();
            $locked = DeviceRegistration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status->value, DeviceRegistration::CONSUMING_STATUSES, true)) {
                return self::ALREADY_HELD;
            }

            if ($quota->remaining() <= 0) {
                return $this->selector->handleExhausted($locked, $quota);
            }

            QuotaReservation::create([
                'team_quota_id' => $quota->id,
                'device_registration_id' => $locked->id,
                'expires_at' => now()->addMinutes(QuotaReservation::TTL_MINUTES),
            ]);
            $this->states->transition($locked, Status::ApplePending, actor: Actor::system('quota'), extra: ['status_reason' => null]);
            $this->audit->record('quota.reserved', $locked, after: [
                'team' => $quota->team->apple_team_id,
                'family' => $quota->device_family->value,
                'remaining' => $quota->remaining(),
            ], actor: Actor::system('quota'));

            return self::RESERVED;
        });
    }

    /**
     * Apple accepted the device: the reservation became a registered slot.
     */
    public function consume(DeviceRegistration $registration): void
    {
        QuotaReservation::query()
            ->where('device_registration_id', $registration->id)
            ->where('status', 'RESERVED')
            ->update(['status' => 'CONSUMED', 'updated_at' => now()]);
    }

    /**
     * The Apple operation failed: give the slot back (FULL_PLAN §6.2 step 6).
     */
    public function release(DeviceRegistration $registration, string $reason): void
    {
        QuotaReservation::query()
            ->where('device_registration_id', $registration->id)
            ->where('status', 'RESERVED')
            ->update(['status' => 'RELEASED', 'released_reason' => mb_substr($reason, 0, 64), 'updated_at' => now()]);
    }

    /**
     * Reservations whose Apple call never came back. Returns how many were released.
     */
    public function releaseExpired(): int
    {
        $expired = QuotaReservation::query()
            ->with('registration')
            ->where('status', 'RESERVED')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($expired as $reservation) {
            $registration = $reservation->registration;
            if ($registration->status === Status::ApplePending && $registration->apple_device_id === null) {
                $this->states->transition($registration, Status::AppleFailed, 'Reservation expired before Apple answered.', Actor::system('quota'), extra: ['status_reason' => 'RESERVATION_EXPIRED']);
                $reservation->forceFill(['status' => 'RELEASED', 'released_reason' => 'EXPIRED'])->save();
            } else {
                $reservation->forceFill(['status' => 'CONSUMED'])->save();
            }
        }

        return $expired->count();
    }

    /**
     * Quota rows for a team's current membership year, one per family that has any.
     *
     * @return list<array{family: string, limit: int, registered: int, reserved: int, remaining: int, apple_registered: int|null, last_synced_at: string|null}>
     */
    public function summary(AppleTeam $team): array
    {
        $year = $team->currentMembershipYear();
        if ($year === null) {
            return [];
        }

        return TeamQuota::query()
            ->where(['apple_team_id' => $team->id, 'membership_year_id' => $year->id])
            ->orderBy('device_family')
            ->get()
            ->map(fn (TeamQuota $quota) => [
                'family' => $quota->device_family->value,
                'limit' => $quota->limit_count,
                'registered' => $quota->registeredCount(),
                'reserved' => $quota->reservedCount(),
                'remaining' => $quota->remaining(),
                'apple_registered' => $quota->apple_registered_count,
                'last_synced_at' => $quota->last_synced_at?->toIso8601ZuluString(),
            ])
            ->values()
            ->all();
    }
}
