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
 */
class ApplePortalLogin extends Command
{
    protected $signature = 'apple:portal-login {apple-id : Apple ID (email) of an Admin of the Apple Developer team}';

    protected $description = 'Sign an Apple ID in to the developer portal so App Groups are created automatically';

    public function handle(SecretStore $secrets, AppGroupProvisioner $portal, AuditService $audit): int
    {
        $user = strtolower(trim((string) $this->argument('apple-id')));
        $password = (string) $this->secret('Apple ID password');
        if ($user === '' || $password === '') {
            $this->error('Apple ID and password are required.');

            return self::FAILURE;
        }

        $secrets->storeEncrypted(AppGroupProvisioner::CREDENTIALS, (string) json_encode(['user' => $user, 'password' => $password]));
        $this->info('Password stored encrypted. Signing in; enter the code Apple sends to your devices when asked.');

        $result = $portal->run(['login'], 600, interactive: true);
        // With a terminal attached the script's JSON goes to the screen; check the session non-interactively.
        $check = $portal->run(['login'], 120);
        if (($check['ok'] ?? false) !== true) {
            $this->error('Sign-in failed: '.($check['message'] ?? $result['message'] ?? 'unknown error'));

            return self::FAILURE;
        }

        $audit->record('apple.portal_login', after: ['apple_id' => $user, 'teams' => $check['teams'] ?? []], actor: Actor::system('cli'));
        $this->info('Signed in. Teams: '.implode(', ', (array) ($check['teams'] ?? [])).'. App Groups are now created automatically.');

        return self::SUCCESS;
    }
}
