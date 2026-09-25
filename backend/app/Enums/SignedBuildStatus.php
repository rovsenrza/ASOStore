<?php

namespace App\Enums;

use App\StateMachines\HasTransitions;
use App\StateMachines\StatusEnum;

/**
 * Lifecycle of a build signed for one device profile (IMPLEMENTATION_PLAN §5.1).
 */
enum SignedBuildStatus: string implements StatusEnum
{
    use HasTransitions;

    case SigningPending = 'SIGNING_PENDING';
    case Signing = 'SIGNING';
    case Signed = 'SIGNED';
    case SignatureVerified = 'SIGNATURE_VERIFIED';
    case Deliverable = 'DELIVERABLE';
    case SigningFailed = 'SIGNING_FAILED';
    case ValidationFailed = 'VALIDATION_FAILED';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::SigningPending => [self::Signing, self::SigningFailed],
            // Back to pending when the runner's lease expires mid-job.
            self::Signing => [self::Signed, self::SigningFailed, self::SigningPending],
            self::Signed => [self::SignatureVerified, self::ValidationFailed],
            self::SignatureVerified => [self::Deliverable],
            self::Deliverable => [self::Expired, self::Revoked],
            self::SigningFailed => [self::SigningPending],
            self::ValidationFailed, self::Expired, self::Revoked => [],
        };
    }
}
