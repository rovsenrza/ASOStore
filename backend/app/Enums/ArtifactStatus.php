<?php

namespace App\Enums;

use App\StateMachines\HasTransitions;
use App\StateMachines\StatusEnum;

/**
 * Lifecycle of an original uploaded IPA (IMPLEMENTATION_PLAN §5.1, G6).
 * Per-device signed builds have their own machine: SignedBuildStatus.
 */
enum ArtifactStatus: string implements StatusEnum
{
    use HasTransitions;

    case Uploaded = 'UPLOADED';
    case Hashing = 'HASHING';
    case Inspecting = 'INSPECTING';
    case ProvenanceReview = 'PROVENANCE_REVIEW';
    case CompatibilityCheck = 'COMPATIBILITY_CHECK';
    case Ready = 'READY';
    case Published = 'PUBLISHED';
    case Rejected = 'REJECTED';
    case InspectionFailed = 'INSPECTION_FAILED';
    case ProvenanceFailed = 'PROVENANCE_FAILED';
    case Quarantined = 'QUARANTINED';
    case Revoked = 'REVOKED';
    case Expired = 'EXPIRED';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Uploaded => [self::Hashing, self::Rejected],
            self::Hashing => [self::Inspecting, self::Rejected],
            self::Inspecting => [self::ProvenanceReview, self::InspectionFailed, self::Rejected, self::Quarantined],
            self::ProvenanceReview => [self::CompatibilityCheck, self::ProvenanceFailed, self::Quarantined],
            self::CompatibilityCheck => [self::Ready, self::Rejected],
            self::Ready => [self::Published, self::Revoked],
            self::Published => [self::Revoked, self::Expired],
            // A reviewer may release a quarantined file back into review.
            self::Quarantined => [self::ProvenanceReview, self::Rejected],
            // Manual re-inspection (POST /admin/artifacts/{id}/inspect).
            self::InspectionFailed => [self::Inspecting],
            self::Rejected, self::ProvenanceFailed, self::Revoked, self::Expired => [],
        };
    }
}
