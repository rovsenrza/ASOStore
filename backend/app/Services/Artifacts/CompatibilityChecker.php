<?php

namespace App\Services\Artifacts;

use App\Models\AppArtifact;
use App\Models\CatalogApp;

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

        // Team eligibility (team_app_eligibilities) is enforced here once Phase 7 adds it.
        $warnings[] = ['code' => 'TEAM_ELIGIBILITY_NOT_CHECKED', 'message' => 'Apple team eligibility for this bundle ID is checked from Phase 7.'];

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }

    public static function publishable(AppArtifact $artifact): bool
    {
        return in_array($artifact->source_type->value, config('storefront.artifacts.publishable_source_types'), true);
    }
}
