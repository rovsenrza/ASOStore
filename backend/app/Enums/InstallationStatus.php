<?php

namespace App\Enums;

use App\StateMachines\HasTransitions;
use App\StateMachines\StatusEnum;

/**
 * One user request to install one app on one device (IMPLEMENTATION_PLAN §5.1, §5.6).
 * DELIVERED is the last state the server can observe (G13).
 */
enum InstallationStatus: string implements StatusEnum
{
    use HasTransitions;

    case Preparing = 'PREPARING';
    case ReadyToInstall = 'READY_TO_INSTALL';
    case Authorized = 'AUTHORIZED';
    case ManifestFetched = 'MANIFEST_FETCHED';
    case Delivered = 'DELIVERED';
    case Failed = 'FAILED';
    case Expired = 'EXPIRED';

    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Preparing => [self::ReadyToInstall, self::Failed],
            self::ReadyToInstall => [self::Authorized, self::Expired, self::Failed],
            // An unused install token expires back to READY_TO_INSTALL; the user can authorize again.
            self::Authorized => [self::ManifestFetched, self::ReadyToInstall, self::Expired, self::Failed],
            self::ManifestFetched => [self::Delivered, self::ReadyToInstall, self::Expired, self::Failed],
            self::Delivered, self::Failed, self::Expired => [],
        };
    }
}
