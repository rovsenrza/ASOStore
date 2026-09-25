<?php

namespace App\Enums;

enum ActivationCodeStatus: string
{
    case Issued = 'ISSUED';
    case Redeemed = 'REDEEMED';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
}
