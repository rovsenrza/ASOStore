<?php

namespace App\Services\Inspection;

use App\Enums\ArtifactStatus;
use RuntimeException;

/**
 * An inspection check that ends the artifact's lifecycle in a failure state.
 */
class InspectionFailure extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ArtifactStatus $outcome,
        public readonly string $reason,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function invalid(string $code, string $message, array $details = []): self
    {
        return new self(ArtifactStatus::InspectionFailed, $code, $message, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function rejected(string $code, string $message, array $details = []): self
    {
        return new self(ArtifactStatus::Rejected, $code, $message, $details);
    }
}
