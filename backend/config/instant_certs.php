<?php

return [
    // Kill switch: off = nobody is provisioned (devices of INSTANT users simply wait).
    'enabled' => (bool) env('INSTANT_CERTS_ENABLED', false),
    'base_url' => env('INSTANT_CERTS_BASE_URL', 'https://apis.nekoo.eu.org/api/v1'),
    'api_key' => env('INSTANT_CERTS_API_KEY'),
    // Customers may pick «instant» themselves when buying (bot and website). Off = admin command only.
    'sell' => (bool) env('INSTANT_CERTS_SELL', true),
    // Below this reseller balance (USD) the admins are told to top up.
    'low_balance_usd' => (float) env('INSTANT_CERTS_LOW_BALANCE_USD', 5),
    // Plans bought when the device has no active certificate yet (GET /plans ids; they may change).
    'plan_iphone' => env('INSTANT_CERTS_PLAN_IPHONE', 'super0'),
    'plan_ipad' => env('INSTANT_CERTS_PLAN_IPAD', 'super_ipad2'),
    // Most installed apps signed for a device as soon as its certificate is in (delta packs, small).
    'presign_popular' => (int) env('INSTANT_CERTS_PRESIGN_POPULAR', 10),
    // Folder the external runner reads its identities from (<name>.cer + <name>.key).
    'identities_dir' => env('INSTANT_CERTS_IDENTITIES_DIR', '/etc/storefront/signing-external'),
];
