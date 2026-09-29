<?php

namespace App\Services\Apple;

use App\Models\AppleTeam;
use Illuminate\Support\Facades\Process;
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

    public function isConfigured(): bool
    {
        return is_file(storage_path('app/private/'.self::CREDENTIALS));
    }

    /**
     * Makes sure $groupId exists in the team and is assigned to $bundleIdentifier.
     *
     * @return bool false when no Apple ID is configured (nothing was done)
     *
     * @throws AppGroupUnavailable when the portal refused (e.g. the session expired)
     */
    public function ensure(AppleTeam $team, string $groupId, string $bundleIdentifier, string $name): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        $result = $this->run(['ensure', $team->apple_team_id, $groupId, self::groupName($name), $bundleIdentifier], 180);
        if (($result['ok'] ?? false) !== true) {
            throw new AppGroupUnavailable((string) ($result['code'] ?? 'PORTAL_ERROR'), (string) ($result['message'] ?? 'The developer portal did not answer.'));
        }

        return true;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    public function run(array $arguments, int $timeoutSeconds, bool $interactive = false): array
    {
        $login = json_decode($this->secrets->read('encrypted-file:'.self::CREDENTIALS), true);
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
        foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
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
        return mb_substr(trim((string) preg_replace('/[^A-Za-z0-9 ]+/', ' ', 'Ru AppStore '.$name)) ?: 'Ru AppStore', 0, 50);
    }
}
