<?php

namespace App\Enums;

/**
 * Which storefront tab a category belongs to.
 */
enum CategoryKind: string
{
    case Apps = 'APPS';
    case Games = 'GAMES';
}
