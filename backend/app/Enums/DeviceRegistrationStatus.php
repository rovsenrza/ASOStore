<?php

namespace App\Enums;

use App\StateMachines\HasTransitions;
use App\StateMachines\StatusEnum;

/**
 * Apple-side registration of a device with a team (IMPLEMENTATION_PLAN §5.1).
 * Blocked states never switch teams on their own (FULL_PLAN §1.3, §6.2).
 */
enum DeviceRegistrationStatus: string implements StatusEnum
{
    use HasTransitions;

    case Enrolled = 'ENROLLED';
    case ApplePending = 'APPLE_PENDING';
    case Eligible = 'ELIGIBLE';
    case AppleFailed = 'APPLE_FAILED';
    case QuotaBlocked = 'QUOTA_BLOCKED';
    case NoEligibleTeam = 'NO_ELIGIBLE_TEAM';
    case Disabled = 'DISABLED';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Enrolled => [self::ApplePending, self::QuotaBlocked, self::NoEligibleTeam, self::AppleFailed],
            self::ApplePending => [self::Eligible, self::AppleFailed],
            self::Eligible => [self::Disabled],
            // Each of these only resumes after an explicit retry or admin approval.
            self::AppleFailed, self::QuotaBlocked, self::NoEligibleTeam, self::Disabled => [self::ApplePending],
        };
    }
}
