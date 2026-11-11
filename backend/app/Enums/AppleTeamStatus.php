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
    // An instant (reseller) certificate's team: signs its own devices, never routed to, no ASC key.
    case External = 'EXTERNAL';

    public function canRegisterDevices(): bool
    {
        return $this === self::Active || $this === self::Expiring;
    }
}
