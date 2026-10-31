<?php

namespace App\Console\Commands;

use App\Services\Apple\AppGroupProvisioner;
use App\Services\Apple\SecretStore;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;

/**
 * Signs an Apple ID in to the developer portal for App Groups (AppGroupProvisioner).
 * Asks for the password (stored encrypted) and the two-factor code; the portal session
 * then lasts about a month. Run it again when App Groups report SESSION_EXPIRED.
 *
 * App Groups can only be created by an account of the team itself, so every team whose owner is
 * not the shared Apple ID needs its own sign-in: `apple:portal-login owner@example.com --team=ABCDE12345`.
 */
class ApplePortalLogin extends Command
{
    protected $signature = 'apple:portal-login
        {apple-id : Apple ID (email) of an Admin of the Apple Developer team}
        {--team= : Team ID (10 characters) this Apple ID signs in for; without it the shared login is stored}';

    protected $description = 'Sign an Apple ID in to the developer portal so App Groups are created automatically';

    public function handle(SecretStore $secrets, AppGroupProvisioner $portal, AuditService $audit): int
    {
        $user = strtolower(trim((string) $this->argument('apple-id')));
        $team = strtoupper(trim((string) $this->option('team'))) ?: null;
        if ($team !== null && ! preg_match('/^[A-Z0-9]{10}$/', $team)) {
            $this->error('A team ID has 10 letters and digits.');

            return self::FAILURE;
        }
        $password = (string) $this->secret('Apple ID password');
        if ($user === '' || $password === '') {
            $this->error('Apple ID and password are required.');

            return self::FAILURE;
        }

        $path = $team === null ? AppGroupProvisioner::CREDENTIALS : AppGroupProvisioner::credentialsFor($team);
        $previous = is_file(storage_path('app/private/'.$path)) ? file_get_contents(storage_path('app/private/'.$path)) : null;
        // Store the password so spaceauth only prompts for the 2FA code, not the password.
        $secrets->storeEncrypted($path, (string) json_encode(['user' => $user, 'password' => $password]));
        $this->info('Password stored encrypted. Signing in; enter the code Apple sends to your devices when asked.');

        // Capture a reusable ~30-day session (one 2FA code). spaceauth writes it to this file; with
        // a terminal attached its own output goes to the screen, so the session comes back via the file.
        $sessionFile = tempnam(sys_get_temp_dir(), 'fls');
        try {
            $result = $portal->run(['spaceauth', $sessionFile], 600, interactive: true, team: $team);
            $session = is_file($sessionFile) ? trim((string) file_get_contents($sessionFile)) : '';
        } finally {
            @unlink($sessionFile);
        }
        if ($session === '') {
            $previous === null ? @unlink(storage_path('app/private/'.$path)) : file_put_contents(storage_path('app/private/'.$path), $previous);
            $this->error('Sign-in failed: '.($result['message'] ?? 'no session was captured. Check the Apple ID, password and 2FA code.'));

            return self::FAILURE;
        }
        // Store the session now so the verification call below reuses it (no second 2FA).
        $secrets->storeEncrypted($path, (string) json_encode(['user' => $user, 'password' => $password, 'session' => $session]));

        $check = $portal->run(['login'], 120, team: $team);
        $teams = array_map('strval', (array) ($check['teams'] ?? []));
        if (($check['ok'] ?? false) !== true || ($team !== null && ! in_array($team, $teams, true))) {
            $previous === null ? @unlink(storage_path('app/private/'.$path)) : file_put_contents(storage_path('app/private/'.$path), $previous);
            $this->error(($check['ok'] ?? false) === true
                ? "Signed in, but this Apple ID is not a member of team {$team} (it sees: ".implode(', ', $teams).'). Nothing was stored.'
                : 'Session check failed: '.($check['message'] ?? 'unknown error'));

            return self::FAILURE;
        }

        // The teams this Apple ID can see: later App Group calls use it only for those teams.
        $secrets->storeEncrypted($path, (string) json_encode([
            'user' => $user, 'password' => $password, 'session' => $session,
            'session_captured_at' => now()->toIso8601String(), 'teams' => $teams,
        ]));
        $audit->record('apple.portal_login', after: ['apple_id' => $user, 'for_team' => $team, 'teams' => $teams], actor: Actor::system('cli'));
        $this->info('Signed in with a ~30-day session. Teams: '.implode(', ', $teams).'. App Groups are now created automatically'.($team !== null ? " for {$team}." : '.'));

        return self::SUCCESS;
    }
}
