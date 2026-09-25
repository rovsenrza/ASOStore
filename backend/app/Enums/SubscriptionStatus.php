<?php

namespace App\Enums;

/**
 * Placeholder until payments exist (FULL_PLAN §4.1); activation codes create these.
 */
enum SubscriptionStatus: string
{
    case Active = 'ACTIVE';
    case Cancelled = 'CANCELLED';
}
