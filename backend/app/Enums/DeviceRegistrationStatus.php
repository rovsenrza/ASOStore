<?php

namespace App\Enums;

use App\StateMachines\HasTransitions;
use App\StateMachines\StatusEnum;

/**
 * Apple-side registration of a device with a team (IMPLEMENTATION_PLAN §5.1).
 * A replacement registration on another team is a separate record; this
 * registration's status never changes its Apple team in place.
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
            // Each of these only resumes after an explicit retry or admin approval;
            // a retry may find the quota situation changed (Phase 7).
            self::AppleFailed => [self::ApplePending, self::QuotaBlocked, self::NoEligibleTeam],
            self::QuotaBlocked => [self::ApplePending, self::NoEligibleTeam],
            self::NoEligibleTeam => [self::ApplePending, self::QuotaBlocked],
            self::Disabled => [self::ApplePending],
        };
    }
}
