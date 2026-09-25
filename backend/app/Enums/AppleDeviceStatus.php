<?php

namespace App\Enums;

/**
 * Device status as App Store Connect reports it.
 */
enum AppleDeviceStatus: string
{
    case Enabled = 'ENABLED';
    case Disabled = 'DISABLED';
    case Processing = 'PROCESSING';
}
