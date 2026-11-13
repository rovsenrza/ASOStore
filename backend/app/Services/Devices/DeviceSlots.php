<?php

namespace App\Services\Devices;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Device;
use App\Models\User;

/**
 * Several iPhones per account, each paid for on its own. A payment (a redeemed code) either
 * extends one device or opens a new, pending one; the next new iPhone that enrolls on the
 * account takes the oldest pending device that still has access.
 *
 * Callers hold the user row lock (ActivationCodeService::redeem, EnrollmentService::complete).
 */
class DeviceSlots
{
    public static function limit(): int
    {
        return max(1, (int) config('storefront.devices.max_per_account', 5));
    }

    /**
     * Which device a payment is for.
     *
     * - $target: extend that device (it must be the user's).
     * - $newDevice: a new pending device (within the limit).
     * - neither: the obvious one — the only device, or the pending one when nothing else is
     *   on the account; a new pending device when the account has none. With several devices
     *   the caller must say which (DEVICE_TARGET_REQUIRED).
     */
    public function target(User $user, ?Device $target = null, bool $newDevice = false): Device
    {
        if ($target !== null) {
            if ($target->user_id !== $user->id) {
                throw new ApiException(ErrorCode::DeviceNotEligible);
            }

            return $target;
        }

        $devices = $user->devices()->orderBy('id')->get();
        if (! $newDevice) {
            if ($devices->count() === 1) {
                return $devices->first();
            }
            if ($devices->count() > 1) {
                // A pending device nobody paid for yet is the only unambiguous choice.
                $unpaid = $devices->filter(fn (Device $device) => ! $device->isEnrolled() && ! $device->hasAccess());
                if ($unpaid->count() === 1) {
                    return $unpaid->first();
                }
                throw new ApiException(ErrorCode::DeviceTargetRequired);
            }
        }

        return $this->open($user, $devices->count());
    }

    /** The pending device a newly enrolling iPhone takes over: the oldest one with access. */
    public function claimable(User $user): ?Device
    {
        return $user->devices()->pending()->orderBy('id')->get()->first(fn (Device $device) => $device->hasAccess());
    }

    private function open(User $user, int $count): Device
    {
        if ($count >= self::limit()) {
            throw new ApiException(ErrorCode::DeviceLimitReached, details: ['limit' => self::limit()]);
        }
        $device = new Device(['user_id' => $user->id]);
        $device->save();

        return $device;
    }
}
