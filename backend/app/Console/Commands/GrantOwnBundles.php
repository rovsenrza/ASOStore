<?php

namespace App\Console\Commands;

use App\Models\AppleTeam;
use App\Models\User;
use App\Services\Catalog\TeamEligibilityGranter;
use Illuminate\Console\Command;

/**
 * Approves a team for every own bundle ID (com.ruappstore.*) the catalog uses. Activating a team
 * in the admin does this by itself; this is for a team activated before that, or from the CLI.
 */
class GrantOwnBundles extends Command
{
    protected $signature = 'apple:grant-own-bundles {team-id : Apple Team ID, e.g. A1B2C3D4E5} {--user=1 : ID of the admin recorded as the approver}';

    protected $description = 'Approve a team for every own bundle ID in the catalog, so overflow devices can install it';

    public function handle(TeamEligibilityGranter $eligibility): int
    {
        $team = AppleTeam::query()->where('apple_team_id', strtoupper((string) $this->argument('team-id')))->first();
        if ($team === null) {
            $this->error('No such team.');

            return self::FAILURE;
        }
        $user = User::query()->find((int) $this->option('user'));
        if ($user === null) {
            $this->error('No such user.');

            return self::FAILURE;
        }

        $granted = $eligibility->grantOwnBundles($team, $user, 'Own bundle IDs granted from the CLI.');
        $this->info("Team {$team->apple_team_id}: {$granted} bundle IDs approved.");

        return self::SUCCESS;
    }
}
