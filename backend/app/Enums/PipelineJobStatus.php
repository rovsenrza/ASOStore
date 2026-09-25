<?php

namespace App\Enums;

use App\StateMachines\HasTransitions;
use App\StateMachines\StatusEnum;

/**
 * Domain job lifecycle, separate from Laravel's queue tables (IMPLEMENTATION_PLAN §5.1, G4).
 */
enum PipelineJobStatus: string implements StatusEnum
{
    use HasTransitions;

    case Queued = 'QUEUED';
    case Leased = 'LEASED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case FailedRetryable = 'FAILED_RETRYABLE';
    case FailedPermanent = 'FAILED_PERMANENT';
    case Cancelled = 'CANCELLED';

    public function allowedTransitions(): array
    {
        return match ($this) {
            // In-process jobs start RUNNING directly; runner jobs are LEASED first.
            self::Queued => [self::Leased, self::Running, self::Cancelled],
            self::Leased => [self::Running, self::Queued, self::Cancelled],
            self::Running => [self::Succeeded, self::FailedRetryable, self::FailedPermanent, self::Queued],
            self::FailedRetryable => [self::Queued, self::FailedPermanent, self::Cancelled],
            // Manual admin retry (POST /admin/jobs/{id}/retry).
            self::FailedPermanent => [self::Queued],
            self::Succeeded, self::Cancelled => [],
        };
    }
}
