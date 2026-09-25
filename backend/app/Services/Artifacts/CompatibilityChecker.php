<?php

namespace App\Services\Artifacts;

use App\Enums\AppleTeamStatus;
use App\Models\AppArtifact;
use App\Models\CatalogApp;
use App\Models\TeamAppEligibility;

/**
 * COMPATIBILITY_CHECK (IMPLEMENTATION_PLAN §5.7, P5-BE-03): decides whether an
 * approved artifact can ever be signed and installed by this storefront.
 */
class CompatibilityChecker
{
    /** Inspection findings that make an artifact impossible to sign or install. */
    private const BLOCKING = [
        'ARM64_MISSING', 'SIMULATOR_BUILD', 'WATCH_APP_UNSUPPORTED', 'APP_CLIP_UNSUPPORTED', 'NESTED_BUNDLE_ID_MISMATCH',
    ];

    /**
     * @return array{blocking: list<array{code: string, message: string}>, warnings: list<array{code: string, message: string}>}
     */
    public function check(AppArtifact $artifact, CatalogApp $app): array
    {
        $blocking = [];
        $warnings = [];

        foreach ($artifact->inspection['compatibility_issues'] ?? [] as $issue) {
            $finding = ['code' => (string) $issue['code'], 'message' => (string) $issue['message']];
            if (in_array($finding['code'], self::BLOCKING, true)) {
                $blocking[] = $finding;
            } else {
                $warnings[] = $finding;
            }
        }

        if ($artifact->source_type !== $app->source_type) {
            $blocking[] = ['code' => 'SOURCE_TYPE_MISMATCH', 'message' => 'The upload declared a different source type than the listing.'];
        }
        if (! self::publishable($artifact)) {
            $blocking[] = ['code' => 'SOURCE_TYPE_NOT_PUBLISHABLE', 'message' => 'This source type is not approved for distribution.'];
        }

        // A team must be approved to distribute this bundle ID (IMPLEMENTATION_PLAN §5.7, P7-BE-03).
        if (config('storefront.artifacts.require_team_eligibility') && ! $this->hasEligibleTeam((string) $artifact->bundle_identifier)) {
            $blocking[] = ['code' => 'TEAM_NOT_ELIGIBLE', 'message' => 'No active Apple team is approved for this bundle ID. Add a team eligibility, then upload again.'];
        }

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }

    private function hasEligibleTeam(string $bundleIdentifier): bool
    {
        return TeamAppEligibility::query()
            ->where(['bundle_identifier' => $bundleIdentifier, 'status' => 'APPROVED'])
            ->whereHas('team', fn ($team) => $team->whereIn('status', [AppleTeamStatus::Active->value, AppleTeamStatus::Expiring->value]))
            ->exists();
    }

    public static function publishable(AppArtifact $artifact): bool
    {
        return in_array($artifact->source_type->value, config('storefront.artifacts.publishable_source_types'), true);
    }
}
