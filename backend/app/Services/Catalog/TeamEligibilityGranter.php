<?php

namespace App\Services\Catalog;

use App\Enums\AppleTeamStatus;
use App\Models\AppleTeam;
use App\Models\CatalogApp;
use App\Models\TeamAppEligibility;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;

/**
 * A listing signed under our own bundle ID prefix (com.ruappstore.*) needs no separate
 * approval: the ID is ours, so every team that can sign is approved for it as soon as the
 * listing carries it. A device moved to another team on quota overflow must be able to
 * install the same catalog, so a team that becomes active also receives every own ID
 * already in use (grantOwnBundles). Other IDs still need an explicit team eligibility.
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

    /**
     * Approves the listing's own bundle ID for every team that can sign.
     *
     * @return list<TeamAppEligibility> The rows created now.
     */
    public function grantFor(CatalogApp $app, User $actor, string $evidence): array
    {
        if (! self::isOwn($app->bundle_identifier)) {
            return [];
        }

        $granted = [];
        foreach ($this->signingTeams() as $team) {
            $row = $this->grant($team, (string) $app->bundle_identifier, $evidence, $actor);
            if ($row !== null) {
                $granted[] = $row;
            }
        }

        return $granted;
    }

    /**
     * Gives a team every own bundle ID the catalog uses: those another team is approved for,
     * and those carried by a listing. Run when a team becomes active.
     */
    public function grantOwnBundles(AppleTeam $team, User $actor, string $evidence): int
    {
        $prefix = (string) config('storefront.artifacts.own_bundle_prefix');
        if ($prefix === '') {
            return 0;
        }

        /** @var Collection<int, string> $bundles */
        $bundles = TeamAppEligibility::query()
            ->where('status', 'APPROVED')->where('bundle_identifier', 'like', $prefix.'%')
            ->pluck('bundle_identifier')
            ->merge(CatalogApp::query()->where('bundle_identifier', 'like', $prefix.'%')->pluck('bundle_identifier'))
            ->filter(fn ($bundle) => self::isOwn($bundle))
            ->unique()->values();

        $granted = 0;
        foreach ($bundles as $bundle) {
            if ($this->grant($team, $bundle, $evidence, $actor, audit: false) !== null) {
                $granted++;
            }
        }
        if ($granted > 0) {
            $this->audit->record('team.eligibility.own_bundles_granted', $team, after: [
                'team' => $team->apple_team_id,
                'granted' => $granted,
            ], reason: $evidence, actor: Actor::user($actor));
        }

        return $granted;
    }

    /**
     * @return Collection<int, AppleTeam>
     */
    private function signingTeams(): Collection
    {
        return AppleTeam::query()
            ->whereIn('status', [AppleTeamStatus::Active->value, AppleTeamStatus::Expiring->value])
            ->orderBy('id')
            ->get()
            // Before any team is active (first set-up), the primary team still gets the ID.
            ->whenEmpty(fn (Collection $none) => $none->merge(array_filter([AppleTeam::primary()])));
    }

    private function grant(AppleTeam $team, string $bundle, string $evidence, User $actor, bool $audit = true): ?TeamAppEligibility
    {
        if (TeamAppEligibility::allows($team->id, $bundle)) {
            return null;
        }

        $row = TeamAppEligibility::query()->updateOrCreate(
            ['apple_team_id' => $team->id, 'bundle_identifier' => $bundle],
            ['evidence' => $evidence, 'status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()],
        );
        if ($audit) {
            $this->audit->record('team.eligibility.approved', $row, after: ['team' => $team->apple_team_id, 'bundle_identifier' => $bundle], actor: Actor::user($actor));
        }

        return $row;
    }
}
