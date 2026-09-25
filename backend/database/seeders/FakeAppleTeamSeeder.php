<?php

namespace Database\Seeders;

use App\Enums\AppleTeamStatus;
use App\Models\AppleTeam;
use App\Models\MembershipYear;
use Illuminate\Database\Seeder;

/**
 * Local development with STOREFRONT_APPLE_DRIVER=fake: a primary team whose
 * registrations are simulated by FakeAppleIntegration.
 */
class FakeAppleTeamSeeder extends Seeder
{
    public const TEAM_ID = 'FAKE000001';

    public function run(): void
    {
        $team = AppleTeam::query()->updateOrCreate(['apple_team_id' => self::TEAM_ID], [
            'name' => 'Fake team (local)',
            'status' => AppleTeamStatus::Active,
            'is_primary' => true,
            'membership_expires_at' => now()->addYear(),
        ]);

        MembershipYear::query()->firstOrCreate(
            ['apple_team_id' => $team->id, 'status' => 'ACTIVE'],
            ['starts_at' => now()->startOfDay(), 'ends_at' => now()->addYear()->endOfDay()],
        );
    }
}
