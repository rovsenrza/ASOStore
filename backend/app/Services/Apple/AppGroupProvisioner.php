<?php

namespace App\Services\Apple;

use App\Models\AppleTeam;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates an App Group and assigns it to an App ID through the developer portal
 * (scripts/apple-app-group.rb, fastlane Spaceship): the App Store Connect API has no
 * endpoint for either. Needs an Apple ID stored with `php artisan apple:portal-login`.
 */
class AppGroupProvisioner
{
    public const CREDENTIALS = 'secrets/apple/portal-login.json.enc';

    public function __construct(private readonly SecretStore $secrets) {}

    /** The login file of one team: the account holder of that team signs in to the portal for it. */
    public static function credentialsFor(string $teamIdentifier): string
    {
        return 'secrets/apple/portal-login-'.strtoupper($teamIdentifier).'.json.enc';
    }

    /**
     * Which stored login serves the team: its own, or the shared one when that Apple ID is known to
     * belong to the team (older logins did not record their teams: only the primary team may use those).
     * Null when no login can see the team, so the portal is not asked at all.
     */
    public function credentialsPath(AppleTeam|string $team): ?string
    {
        $identifier = $team instanceof AppleTeam ? $team->apple_team_id : $team;
        if (is_file(storage_path('app/private/'.self::credentialsFor($identifier)))) {
            return self::credentialsFor($identifier);
        }
        if (! is_file(storage_path('app/private/'.self::CREDENTIALS))) {
            return null;
        }
        $teams = json_decode($this->secrets->read('encrypted-file:'.self::CREDENTIALS), true)['teams'] ?? null;
        if (is_array($teams)) {
            return in_array($identifier, $teams, true) ? self::CREDENTIALS : null;
        }

        return $team instanceof AppleTeam && $team->is_primary ? self::CREDENTIALS : null;
    }

    public function isConfigured(?AppleTeam $team = null): bool
    {
        if ($team === null) {
            return is_file(storage_path('app/private/'.self::CREDENTIALS));
        }

        return $this->credentialsPath($team) !== null;
    }

    /**
     * Makes sure $groupId exists in the team and is assigned to $bundleIdentifier.
     *
     * @return bool false when no Apple ID is configured for the team (nothing was done)
     *
     * @throws AppGroupUnavailable when the portal refused (e.g. the session expired)
     */
    public function ensure(AppleTeam $team, string $groupId, string $bundleIdentifier, string $name): bool
    {
        if (! $this->isConfigured($team)) {
            return false;
        }

        // The assignment belongs to the App ID, not the device: once done, later profiles skip the portal.
        $assigned = "apple-portal:group:{$team->apple_team_id}:{$groupId}:{$bundleIdentifier}";
        if (Cache::get($assigned) === true) {
            return true;
        }

        try {
            // The portal answers 503 to parallel sessions of one Apple ID: one call per team at a time.
            Cache::lock('apple-portal:'.$team->apple_team_id, 200)->block(190, fn () => $this->assign($team, $groupId, $bundleIdentifier, $name));
        } catch (LockTimeoutException) {
            throw new AppGroupUnavailable('PORTAL_BUSY', 'The developer portal is busy with other App IDs.');
        }
        Cache::put($assigned, true, now()->addDays(30));

        return true;
    }

    /**
     * @throws AppGroupUnavailable
     */
    protected function assign(AppleTeam $team, string $groupId, string $bundleIdentifier, string $name): void
    {
        $result = $this->run(['ensure', $team->apple_team_id, $groupId, self::groupName($name), $bundleIdentifier], 180, team: $team);
        if (($result['ok'] ?? false) !== true) {
            throw new AppGroupUnavailable((string) ($result['code'] ?? 'PORTAL_ERROR'), (string) ($result['message'] ?? 'The developer portal did not answer.'));
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    public function run(array $arguments, int $timeoutSeconds, bool $interactive = false, AppleTeam|string|null $team = null): array
    {
        // No team: the shared login (the `apple:portal-login` without --team).
        $path = $team === null ? self::CREDENTIALS : $this->credentialsPath($team);
        if ($path === null) {
            return ['ok' => false, 'code' => 'NOT_CONFIGURED', 'message' => 'No Apple ID is signed in to the developer portal for this team.'];
        }
        $login = json_decode($this->secrets->read('encrypted-file:'.$path), true);
        $process = Process::timeout($timeoutSeconds)
            ->env(['FASTLANE_USER' => (string) ($login['user'] ?? ''), 'FASTLANE_PASSWORD' => (string) ($login['password'] ?? ''),
                'FASTLANE_SKIP_UPDATE_CHECK' => '1', 'FASTLANE_HIDE_TIMESTAMP' => '1', 'LANG' => 'en_US.UTF-8'])
            ->path(base_path());
        if ($interactive) {
            $process = $process->tty();
        }

        try {
            $output = $process->run([(string) config('storefront.apple.portal_ruby'), base_path('../scripts/apple-app-group.rb'), ...$arguments])->output();
        } catch (Throwable $e) {
            return ['ok' => false, 'code' => 'PORTAL_ERROR', 'message' => $e->getMessage()];
        }

        // The last JSON line; Spaceship may print notices before it.
        foreach (array_reverse(preg_split('/\R/u', trim($output)) ?: []) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && array_key_exists('ok', $decoded)) {
                return $decoded;
            }
        }

        return ['ok' => false, 'code' => 'PORTAL_ERROR', 'message' => mb_substr(trim($output), -500) ?: 'No answer from the portal script.'];
    }

    /** Apple accepts letters, digits and spaces in a group's name. */
    public static function groupName(string $name): string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 ]+/', ' ', 'Ru App Store '.Str::ascii($name))));

        return mb_substr($clean ?: 'Ru App Store', 0, 50);
    }
}
