<?php

namespace App\Console\Commands;

use App\Enums\AppleTeamStatus;
use App\Models\AppleCredential;
use App\Models\AppleTeam;
use App\Models\MembershipYear;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleIntegration;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Connects the primary Apple Developer team (Phase 3 is single-team; the
 * admin UI for teams arrives in Phase 7). Verifies the key against App Store
 * Connect before activating the team.
 */
class AppleConnect extends Command
{
    protected $signature = 'apple:connect
        {team-id : Apple Team ID, e.g. A1B2C3D4E5}
        {--name= : Display name}
        {--issuer-id= : App Store Connect API issuer ID}
        {--key-id= : App Store Connect API key ID}
        {--key= : Vault reference from apple:store-key}
        {--membership-ends= : End of the current membership year (YYYY-MM-DD)}';

    protected $description = 'Connect the primary Apple Developer team and verify its API key';

    public function handle(AppleIntegration $apple, AuditService $audit): int
    {
        $fake = config('storefront.apple.driver') === 'fake';
        $ends = $this->option('membership-ends') ? Carbon::parse((string) $this->option('membership-ends'))->endOfDay() : null;

        if (! $fake && (! $this->option('issuer-id') || ! $this->option('key-id') || ! $this->option('key') || ! $ends)) {
            $this->error('--issuer-id, --key-id, --key and --membership-ends are required.');

            return self::FAILURE;
        }

        $team = DB::transaction(function () use ($ends, $fake) {
            AppleTeam::query()->update(['is_primary' => false]);
            $team = AppleTeam::query()->updateOrCreate(
                ['apple_team_id' => strtoupper((string) $this->argument('team-id'))],
                ['name' => $this->option('name') ?: (string) $this->argument('team-id'), 'is_primary' => true, 'membership_expires_at' => $ends ?? now()->addYear()],
            );

            if (! $fake) {
                $team->credentials()->where('status', 'ACTIVE')->update(['status' => 'REVOKED']);
                AppleCredential::create([
                    'apple_team_id' => $team->id,
                    'issuer_id' => $this->option('issuer-id'),
                    'key_id' => strtoupper((string) $this->option('key-id')),
                    'vault_reference' => $this->option('key'),
                ]);
            }

            $end = $ends ?? now()->addYear();
            MembershipYear::query()->firstOrCreate(
                ['apple_team_id' => $team->id, 'ends_at' => $end],
                ['starts_at' => $end->copy()->subYear(), 'status' => 'ACTIVE'],
            );

            return $team;
        });

        try {
            $apple->verifyCredentials($team->load('activeCredential'));
        } catch (AppleException $e) {
            $team->forceFill(['status' => AppleTeamStatus::Disconnected])->save();
            $audit->record('apple_team.verification_failed', $team, reason: $e->getMessage(), actor: Actor::system('cli'));
            $this->error('Apple rejected the connection: '.$e->getMessage());

            return self::FAILURE;
        }

        $team->forceFill(['status' => AppleTeamStatus::Active, 'last_verified_at' => now()])->save();
        $audit->record('apple_team.connected', $team, after: ['driver' => config('storefront.apple.driver')], actor: Actor::system('cli'));
        $this->info("Team {$team->apple_team_id} is connected and primary. Waiting devices are registered within five minutes.");

        return self::SUCCESS;
    }
}
