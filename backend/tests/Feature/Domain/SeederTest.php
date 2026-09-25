<?php

use App\Enums\AppVisibility;
use App\Enums\RoleSlug;
use App\Models\AppCategory;
use App\Models\CatalogApp;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DatabaseSeeder;

it('seeds roles, one admin and the demo catalog idempotently', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', AdminUserSeeder::EMAIL)->sole();

    expect(Role::pluck('slug')->sort()->values()->all())->toBe(['admin', 'catalog_manager', 'customer', 'support'])
        ->and($admin->hasRole(RoleSlug::Admin))->toBeTrue()
        ->and(CatalogApp::query()->visibleToCustomers()->count())->toBe(13)
        ->and(AppCategory::query()->where('kind', 'GAMES')->count())->toBe(3)
        ->and(CatalogApp::where('is_storefront', true)->sole()->visibility)->toBe(AppVisibility::Hidden);
});
