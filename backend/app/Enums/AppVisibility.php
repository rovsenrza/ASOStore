<?php

namespace App\Enums;

/**
 * Whether a catalog listing is shown to customers. Independent of whether any
 * artifact is installable — that is reported per device by install_state.
 */
enum AppVisibility: string
{
    case Draft = 'DRAFT';
    case Hidden = 'HIDDEN';
    case Published = 'PUBLISHED';
}
