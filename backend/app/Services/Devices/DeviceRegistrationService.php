<?php

namespace App\Services\Devices;

use App\Enums\AppleDeviceStatus;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Models\AppleTeam;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Services\Apple\AppleDevice;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleIntegration;
use App\Services\Apple\AppleRetryableException;
use App\Services\Audit\Actor;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;

/**
 * Registers enrolled devices with the primary Apple team (IMPLEMENTATION_PLAN
 * P3-BE-02..04). A device slot is reserved under a row lock on the team, so
 * concurrent registrations can never exceed the per-family limit. At the
 * limit the registration is blocked; no other team is tried (FULL_PLAN §1.3,
 * §6.2 — eligible-team selection with admin approval arrives in Phase 7).
 */
class DeviceRegistrationService
{
    /**
     * Statuses from which a registration attempt may (re)start.
     */
    private const STARTABLE = [Status::Enrolled, Status::AppleFailed, Status::QuotaBlocked, Status::NoEligibleTeam];

    public function __construct(
        private readonly AppleIntegration $apple,
        private readonly StateMachine $states,
    ) {}

    /**
     * The registration for the device with the primary team's current
     * membership year, created if needed. Null until a team is connected.
     */
    public function request(Device $device): ?DeviceRegistration
    {
        $team = AppleTeam::primary();
        $year = $team?->currentMembershipYear();
        if ($team === null || $year === null) {
            return null;
        }

        return DeviceRegistration::query()->firstOrCreate(
            ['apple_team_id' => $team->id, 'udid_hash' => $device->udid_hash, 'membership_year_id' => $year->id],
            ['device_id' => $device->id, 'device_family' => $device->device_family, 'status' => Status::Enrolled],
        );
    }

    /**
     * @throws AppleRetryableException when Apple is rate-limiting or unavailable; the job retries later.
     */
    public function register(DeviceRegistration $registration): void
    {
        $registration->refresh()->load(['team.activeCredential', 'device']);
        $team = $registration->team;

        $resumable = $registration->status === Status::ApplePending && $registration->apple_device_id === null;
        if (! in_array($registration->status, self::STARTABLE, true) && ! $resumable) {
            return;
        }

        if (! $this->apple->isConfigured($team) || ! $team->status->canRegisterDevices()) {
            $registration->forceFill(['status_reason' => 'APPLE_NOT_CONNECTED'])->save();

            return;
        }

        if (! $this->reserveSlot($registration)) {
            return;
        }

        $registration->refresh();
        $registration->increment('attempts');

        try {
            $udid = (string) $registration->device->udid_encrypted;
            $appleDevice = $this->apple->findDevice($team, $udid)
                ?? $this->apple->registerDevice($team, $udid, $this->appleDeviceName($registration->device));
            $this->apply($registration, $appleDevice);
        } catch (AppleRetryableException $e) {
            $registration->forceFill(['status_reason' => $e->reason])->save();

            throw $e;
        } catch (AppleException $e) {
            $this->fail($registration, $e->reason, $e->getMessage());
        }
    }

    /**
     * Polls Apple for a registration that is still processing.
     *
     * @throws AppleRetryableException
     */
    public function sync(DeviceRegistration $registration): void
    {
        $registration->refresh()->load('team.activeCredential');
        if ($registration->status !== Status::ApplePending || $registration->apple_device_id === null) {
            return;
        }

        try {
            $this->apply($registration, $this->apple->getDevice($registration->team, $registration->apple_device_id));
        } catch (AppleRetryableException $e) {
            throw $e;
        } catch (AppleException $e) {
            $this->fail($registration, $e->reason, $e->getMessage());
        }
    }

    /**
     * Gives up on an attempt that kept failing (the job's retries are exhausted).
     */
    public function fail(DeviceRegistration $registration, string $reason, ?string $detail = null): void
    {
        $registration->refresh();
        if ($registration->status->canTransitionTo(Status::AppleFailed)) {
            $this->states->transition($registration, Status::AppleFailed, $detail ?? $reason, Actor::system('apple'), extra: ['status_reason' => $reason]);
        }
    }

    private function reserveSlot(DeviceRegistration $registration): bool
    {
        return DB::transaction(function () use ($registration) {
            // Serialises slot counting per team.
            AppleTeam::query()->whereKey($registration->apple_team_id)->lockForUpdate()->first();
            $locked = DeviceRegistration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === Status::ApplePending) {
                return true;
            }

            $used = DeviceRegistration::query()
                ->where('apple_team_id', $locked->apple_team_id)
                ->where('membership_year_id', $locked->membership_year_id)
                ->where('device_family', $locked->device_family->value)
                ->whereIn('status', DeviceRegistration::CONSUMING_STATUSES)
                ->whereKeyNot($locked->getKey())
                ->count();

            if ($used >= (int) config('storefront.apple.device_limit_per_family')) {
                if ($locked->status !== Status::QuotaBlocked) {
                    $this->states->transition($locked, Status::QuotaBlocked, 'Device limit reached for this family and membership year.', Actor::system('quota'), extra: ['status_reason' => 'QUOTA_EXHAUSTED']);
                }

                return false;
            }

            $this->states->transition($locked, Status::ApplePending, actor: Actor::system('apple'), extra: ['status_reason' => null]);

            return true;
        });
    }

    private function apply(DeviceRegistration $registration, AppleDevice $appleDevice): void
    {
        $registration->forceFill([
            'apple_device_id' => $appleDevice->id,
            'registered_at' => $registration->registered_at ?? now(),
            'last_synced_at' => now(),
        ])->save();

        match ($appleDevice->status) {
            AppleDeviceStatus::Enabled => $this->states->transition($registration, Status::Eligible, actor: Actor::system('apple'), extra: ['eligible_at' => now(), 'status_reason' => null]),
            AppleDeviceStatus::Processing => $registration->forceFill(['status_reason' => 'APPLE_PROCESSING'])->save(),
            AppleDeviceStatus::Disabled => $this->fail($registration, 'APPLE_DEVICE_DISABLED', 'Apple lists this device as disabled.'),
        };
    }

    /**
     * Shown in the Apple developer portal. No personal data.
     */
    private function appleDeviceName(Device $device): string
    {
        return 'Storefront '.substr($device->public_id, -10);
    }
}
