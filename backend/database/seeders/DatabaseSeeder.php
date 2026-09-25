<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RoleSeeder::class, AdminUserSeeder::class]);

        if (app()->environment('local', 'testing')) {
            $this->call(DemoCatalogSeeder::class);
        }

        if (config('storefront.apple.driver') === 'fake') {
            $this->call(FakeAppleTeamSeeder::class);
        }
    }
}
