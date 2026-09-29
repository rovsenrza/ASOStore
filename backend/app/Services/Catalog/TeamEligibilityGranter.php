<?php

namespace App\Services\Catalog;

use App\Models\AppleTeam;
use App\Models\CatalogApp;
use App\Models\TeamAppEligibility;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;

/**
 * A listing signed under our own bundle ID prefix (com.ruappstore.*) needs no separate
 * approval: the ID is ours, so the primary team is approved for it as soon as the
 * listing carries it. Other IDs still need an explicit team eligibility.
 */
class TeamEligibilityGranter
{
    public function __construct(private readonly AuditService $audit) {}

    public static function isOwn(?string $bundleIdentifier): bool
    {
        $prefix = (string) config('storefront.artifacts.own_bundle_prefix');

        return $prefix !== '' && is_string($bundleIdentifier) && str_starts_with($bundleIdentifier, $prefix)
            && strlen($bundleIdentifier) > strlen($prefix);
    }

    public function grantFor(CatalogApp $app, User $actor, string $evidence): ?TeamAppEligibility
    {
        $team = AppleTeam::primary();
        if ($team === null || ! self::isOwn($app->bundle_identifier)) {
            return null;
        }
        if (TeamAppEligibility::allows($team->id, (string) $app->bundle_identifier)) {
            return null;
        }

        $row = TeamAppEligibility::query()->updateOrCreate(
            ['apple_team_id' => $team->id, 'bundle_identifier' => $app->bundle_identifier],
            ['evidence' => $evidence, 'status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()],
        );
        $this->audit->record('team.eligibility.approved', $row, after: ['team' => $team->apple_team_id, 'bundle_identifier' => $app->bundle_identifier], actor: Actor::user($actor));

        return $row;
    }
}
