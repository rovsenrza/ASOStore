<?php

namespace App\Enums;

/**
 * Roles from IMPLEMENTATION_PLAN §5.9.
 */
enum RoleSlug: string
{
    case Customer = 'customer';
    case Support = 'support';
    case CatalogManager = 'catalog_manager';
    case Admin = 'admin';

    /**
     * Roles that may use the admin panel.
     *
     * @return list<self>
     */
    public static function staff(): array
    {
        return [self::Support, self::CatalogManager, self::Admin];
    }

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'Клиент',
            self::Support => 'Поддержка',
            self::CatalogManager => 'Менеджер каталога',
            self::Admin => 'Администратор',
        };
    }
}
