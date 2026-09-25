<?php

namespace Database\Seeders;

use App\Enums\RoleSlug;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public const EMAIL = 'admin@storefront.test';

    public function run(): void
    {
        if (User::where('email', self::EMAIL)->exists()) {
            return;
        }

        $configured = config('storefront.seed_admin_password');
        $password = $configured ?: Str::password(20, symbols: false);

        $admin = User::create([
            'name' => 'Администратор',
            'email' => self::EMAIL,
            'password' => $password,
        ]);
        $admin->roles()->attach(Role::where('slug', RoleSlug::Admin->value)->firstOrFail());

        if (! $configured) {
            $this->command->warn('Admin '.self::EMAIL.' created with password: '.$password);
        }
    }
}
