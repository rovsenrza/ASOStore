<?php

namespace App\Enums;

/**
 * Where a catalog item's artifacts come from (FULL_PLAN §5.1).
 */
enum SourceType: string
{
    case OwnBuild = 'OWN_BUILD';
    case PartnerBuild = 'PARTNER_BUILD';
    case OpenSourceBuild = 'OPEN_SOURCE_BUILD';
    case AlternativeMarketplacePackage = 'ALTERNATIVE_MARKETPLACE_PACKAGE';
    case UserImport = 'USER_IMPORT';
    case CustomerProvided = 'CUSTOMER_PROVIDED';
}
