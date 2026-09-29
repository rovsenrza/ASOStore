<?php

namespace App\Enums;

/**
 * The install CTA state the API reports for one app and the calling device.
 * Clients render the CTA from this value only (FULL_PLAN §4.2, §11).
 */
enum InstallStateStatus: string
{
    /** No installable artifact exists for this listing. */
    case Unavailable = 'unavailable';
    /** An artifact exists, but this user or device cannot install it yet; see reason. */
    case NotEligible = 'not_eligible';
    case Get = 'get';
    case Preparing = 'preparing';
    case ReadyToInstall = 'ready_to_install';
    /** No longer reported: a delivered app may have been deleted since, so it is `get` again. */
    case Delivered = 'delivered';
    case UpdateAvailable = 'update_available';
    case Failed = 'failed';
}
