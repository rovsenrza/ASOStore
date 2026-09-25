<?php

namespace App\Services\Inspection;

use App\Enums\ArtifactStatus;

/**
 * Outcome of one IPA inspection. The report is stored on the artifact for reviewers.
 */
final readonly class InspectionResult
{
    /**
     * @param  ArtifactStatus  $outcome  PROVENANCE_REVIEW when every check passed, otherwise the failure state.
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public ArtifactStatus $outcome,
        public ?string $failureCode,
        public array $report,
    ) {}

    public function passed(): bool
    {
        return $this->outcome === ArtifactStatus::ProvenanceReview;
    }

    public function bundleValue(string $key): ?string
    {
        $value = $this->report['bundle'][$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
