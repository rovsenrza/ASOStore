<?php

namespace App\Support;

use App\Enums\RoleSlug;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Admin permissions by role (IMPLEMENTATION_PLAN §5.9). Routes check these
 * with ->can(); the admin UI reads the granted list from /admin/auth/me.
 */
final class Abilities
{
    /**
     * @var array<string, list<RoleSlug>>
     */
    public const MAP = [
        'users.view' => [RoleSlug::Support, RoleSlug::Admin],
        'users.manage' => [RoleSlug::Admin],
        'activation-codes.view' => [RoleSlug::Support, RoleSlug::Admin],
        'activation-codes.manage' => [RoleSlug::Admin],
        'audit.view' => [RoleSlug::Support, RoleSlug::CatalogManager, RoleSlug::Admin],
        'catalog.view' => [RoleSlug::Support, RoleSlug::CatalogManager, RoleSlug::Admin],
        'catalog.manage' => [RoleSlug::CatalogManager, RoleSlug::Admin],
        'artifacts.view' => [RoleSlug::Support, RoleSlug::CatalogManager, RoleSlug::Admin],
        'artifacts.manage' => [RoleSlug::CatalogManager, RoleSlug::Admin],
        'devices.view' => [RoleSlug::Support, RoleSlug::Admin],
        'devices.manage' => [RoleSlug::Admin],
        'devices.reveal-udid' => [RoleSlug::Admin],
    ];

    public static function register(): void
    {
        foreach (self::MAP as $ability => $roles) {
            Gate::define($ability, fn (User $user) => $user->hasRole(...$roles));
        }
    }

    /**
     * @return list<string>
     */
    public static function grantedTo(User $user): array
    {
        return array_values(array_filter(array_keys(self::MAP), fn (string $ability) => Gate::forUser($user)->allows($ability)));
    }
}
