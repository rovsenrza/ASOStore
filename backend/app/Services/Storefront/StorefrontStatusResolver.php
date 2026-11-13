<?php

namespace App\Services\Storefront;

use App\Enums\DeviceRegistrationStatus as Registration;
use App\Enums\ErrorCode;
use App\Models\Device;
use App\Models\User;

/**
 * Where the caller is in the setup journey and what to do next
 * (IMPLEMENTATION_PLAN P3-BE-05). Shared by the portal and the native app.
 */
class StorefrontStatusResolver
{
    /**
     * @return array{stage: string, next_action: string, blocking_reason: string|null, device: array<string, mixed>|null}
     */
    public function resolve(?User $user, bool $awaitingEmail = false, ?Device $current = null): array
    {
        $this->pending = false;
        if ($user === null) {
            return $this->stage('signed_out', 'sign_in');
        }
        if (! $user->isActive()) {
            return $this->stage('blocked', 'blocked', ErrorCode::AccountSuspended);
        }
        // Website sign-ups confirm their email first; the native app never sees this stage.
        if ($awaitingEmail) {
            return $this->stage('email_verification_required', 'verify_email');
        }
        // Access is paid per iPhone. The stage is that of the iPhone asking (CurrentDevice), and
        // a paid iPhone not connected yet is announced so the page can offer to connect it.
        $pending = app(\App\Services\Devices\DeviceSlots::class)->claimable($user) !== null;
        $device = ($current ?? $user->latestDevice()->first())?->loadMissing('latestRegistration');
        if ($device === null) {
            return $pending ? $this->stage('device_required', 'enroll_device', pending: true) : $this->stage('activation_required', 'redeem_activation');
        }

        $registration = $device->latestRegistration;
        $summary = self::presentDevice($device);
        if (! $user->isStaff() && ! $device->hasAccess()) {
            return $this->stage('activation_required', 'redeem_activation', ErrorCode::SubscriptionRequired, $summary, $pending);
        }
        $this->pending = $pending;

        return match ($registration?->status) {
            null, Registration::Enrolled, Registration::ApplePending => $this->stage('device_pending', 'wait_apple', device: $summary),
            Registration::Eligible => $device->storefront_claimed_at
                ? $this->stage('storefront_installed', 'open_storefront', device: $summary)
                : $this->stage('storefront_ready', 'install_storefront', device: $summary),
            Registration::QuotaBlocked => $this->stage('blocked', 'blocked', ErrorCode::QuotaExhausted, $summary),
            Registration::NoEligibleTeam => $this->stage('blocked', 'blocked', ErrorCode::NoEligibleTeam, $summary),
            Registration::AppleFailed => $this->stage('blocked', 'blocked', ErrorCode::AppleUnavailable, $summary),
            Registration::Disabled => $this->stage('blocked', 'blocked', ErrorCode::DeviceNotEligible, $summary),
        };
    }

    /**
     * Customer-facing device summary: never the UDID, only its last four characters.
     *
     * @return array<string, mixed>
     */
    public static function presentDevice(Device $device): array
    {
        $registration = $device->latestRegistration;

        return [
            'id' => $device->public_id,
            'product' => $device->product,
            'family' => $device->device_family->value,
            'os_version' => $device->os_version,
            'udid_hint' => $device->maskedUdid(),
            'enrolled_at' => $device->enrolled_at?->toIso8601ZuluString(),
            'storefront_claimed' => $device->storefront_claimed_at !== null,
            'registration' => $registration ? [
                'status' => $registration->status->value,
                'reason' => $registration->status_reason,
                'updated_at' => $registration->updated_at?->toIso8601ZuluString(),
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $device
     * @return array{stage: string, next_action: string, blocking_reason: string|null, device: array<string, mixed>|null}
     */
    private function stage(string $stage, string $nextAction, ?ErrorCode $blockingReason = null, ?array $device = null, ?bool $pending = null): array
    {
        return [
            'stage' => $stage,
            'next_action' => $nextAction,
            'blocking_reason' => $blockingReason?->value,
            'device' => $device,
            // A paid iPhone waits to be connected (install the profile on it).
            'pending_device' => $pending ?? $this->pending,
        ];
    }

    private bool $pending = false;
}
