<?php

namespace App\Enums;

/**
 * FULL_PLAN §6.3. Only ACTIVE teams register devices.
 */
enum AppleTeamStatus: string
{
    case PendingVerification = 'PENDING_VERIFICATION';
    case Active = 'ACTIVE';
    case Expiring = 'EXPIRING';
    case Suspended = 'SUSPENDED';
    case Revoked = 'REVOKED';
    case Disconnected = 'DISCONNECTED';

    public function canRegisterDevices(): bool
    {
        return $this === self::Active || $this === self::Expiring;
    }
}
