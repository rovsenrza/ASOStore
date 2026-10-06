<?php

namespace App\Services\Devices;

use App\Enums\AppleDeviceStatus;
use App\Enums\DeviceRegistrationStatus as Status;
use App\Models\AppleTeam;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Notifications\DeviceReadyNotification;
use App\Services\Apple\AppleDevice;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleIntegration;
use App\Services\Apple\AppleRetryableException;
use App\Services\Audit\Actor;
use App\Services\Quotas\QuotaService;
use App\Services\Signing\BuildWarmup;
use App\StateMachines\StateMachine;
use Throwable;

/**
 * Registers enrolled devices with an Apple team (IMPLEMENTATION_PLAN P3-BE-02,
 * P7-BE-02/03). New devices go to the primary team. A slot is reserved under a
 * row lock on the team's quota row (QuotaService), so concurrent registrations
 * can never exceed the per-family limit. At the limit, a fully configured
 * variant on another team can be selected automatically; otherwise the
 * legacy approval/blocking path applies (FULL_PLAN §1.3, §6.2).
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
        private readonly QuotaService $quotas,
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

        if (! in_array($this->quotas->reserve($registration), [QuotaService::RESERVED, QuotaService::ALREADY_HELD], true)) {
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
        if ($registration->apple_device_id === null) {
            $this->quotas->release($registration, $reason);
        }
    }

    private function apply(DeviceRegistration $registration, AppleDevice $appleDevice): void
    {
        $becameEligible = $appleDevice->status === AppleDeviceStatus::Enabled && $registration->status !== Status::Eligible;
        // Apple kept the device processing for a while: the customer may have left the page.
        $waitedForApple = $registration->status_reason === 'APPLE_PROCESSING';
        $registration->forceFill([
            'apple_device_id' => $appleDevice->id,
            'registered_at' => $registration->registered_at ?? now(),
            'last_synced_at' => now(),
        ])->save();
        // Apple counts the device from now on, even if it is later disabled.
        $this->quotas->consume($registration);

        match ($appleDevice->status) {
            AppleDeviceStatus::Enabled => $this->states->transition($registration, Status::Eligible, actor: Actor::system('apple'), extra: ['eligible_at' => now(), 'status_reason' => null]),
            AppleDeviceStatus::Processing => $registration->forceFill(['status_reason' => 'APPLE_PROCESSING'])->save(),
            AppleDeviceStatus::Disabled => $this->fail($registration, 'APPLE_DEVICE_DISABLED', 'Apple lists this device as disabled.'),
        };

        if ($becameEligible) {
            app(BuildWarmup::class)->forDevice($registration->device->fresh());
            if ($waitedForApple) {
                $this->notifyReady($registration->device);
            }
        }
    }

    /** Tells the owner by email that the iPhone can now be set up; a mail failure never stops registration. */
    private function notifyReady(Device $device): void
    {
        try {
            $device->user?->notify(new DeviceReadyNotification($device));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Shown in the Apple developer portal. No personal data.
     */
    private function appleDeviceName(Device $device): string
    {
        return config('storefront.brand').' '.substr($device->public_id, -10);
    }
}
