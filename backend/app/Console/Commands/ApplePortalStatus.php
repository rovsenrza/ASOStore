<?php

namespace App\Console\Commands;

use App\Models\AppleTeam;
use App\Services\Apple\AppGroupProvisioner;
use Illuminate\Console\Command;

/**
 * Which Apple teams can get App Groups: has the team a developer-portal login, and is its
 * session still alive (it lasts about a month). Fix a row with `apple:portal-login … --team=`.
 */
class ApplePortalStatus extends Command
{
    protected $signature = 'apple:portal-status {--no-check : Only list the stored logins, do not ask the portal}';

    protected $description = 'Show which Apple teams have a working developer-portal login for App Groups';

    public function handle(AppGroupProvisioner $portal): int
    {
        $rows = [];
        $missing = 0;
        foreach (AppleTeam::query()->orderBy('id')->get() as $team) {
            $path = $portal->credentialsPath($team);
            if ($path === null) {
                $missing++;
                $rows[] = [$team->apple_team_id, $team->name, 'NO LOGIN', '-'];

                continue;
            }
            $own = $path === AppGroupProvisioner::credentialsFor($team->apple_team_id) ? 'own' : 'shared';
            if ($this->option('no-check')) {
                $rows[] = [$team->apple_team_id, $team->name, "stored ({$own})", '-'];

                continue;
            }
            $check = $portal->run(['login'], 120, team: $team);
            $alive = ($check['ok'] ?? false) === true && in_array($team->apple_team_id, array_map('strval', (array) ($check['teams'] ?? [])), true);
            $alive || $missing++;
            $rows[] = [$team->apple_team_id, $team->name, "stored ({$own})", $alive ? 'session OK' : 'SESSION EXPIRED: '.mb_substr((string) ($check['code'] ?? 'unknown'), 0, 40)];
        }
        $this->table(['Team', 'Name', 'Login', 'Portal'], $rows);

        return $missing === 0 ? self::SUCCESS : self::FAILURE;
    }
}
