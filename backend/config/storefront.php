<?php

return [

    /*
    | Visible brand placeholder until the final brand is chosen (PRODUCT.md).
    */
    'brand' => env('APP_NAME', '[BRAND]'),

    /*
    | API version reported by /api/v1/health.
    */
    'api_version' => '1.0.0-dev',

    /*
    | Key for the UDID blind index (HMAC-SHA256). Kept separate from APP_KEY so
    | rotating the encryption key does not break uniqueness lookups.
    */
    'udid_hmac_key' => env('STOREFRONT_UDID_HMAC_KEY'),

    /*
    | Create MySQL triggers that make audit_logs append-only and protect the
    | immutable columns of app_artifacts. Disable only on hosts that forbid
    | triggers, and then apply the fallback in IMPLEMENTATION_PLAN §5.3.
    */
    'db_immutability_triggers' => (bool) env('STOREFRONT_DB_IMMUTABILITY_TRIGGERS', true),

    /*
    | Password for the seeded admin account (AdminUserSeeder). A random one is
    | generated and printed when empty.
    */
    'seed_admin_password' => env('SEED_ADMIN_PASSWORD'),

    'auth' => [
        // Native app tokens (IMPLEMENTATION_PLAN D3).
        'access_token_minutes' => 15,
        'refresh_token_days' => 30,
        // Time allowed between the password and TOTP steps of staff sign-in.
        'admin_totp_pending_minutes' => 5,
    ],

    'activation' => [
        'max_batch' => 500,
    ],

    'apple' => [
        // disabled (no account yet) | fake (local development) | appstoreconnect
        'driver' => env('STOREFRONT_APPLE_DRIVER', 'disabled'),
        'api_base_url' => env('STOREFRONT_APPLE_API_URL', 'https://api.appstoreconnect.apple.com/v1'),
        // How long the fake driver keeps a new device PROCESSING, to exercise the pending UI.
        'fake_processing_seconds' => (int) env('STOREFRONT_APPLE_FAKE_PROCESSING_SECONDS', 20),
        // Apple's limit per product family per membership year (FULL_PLAN §1.3).
        'device_limit_per_family' => (int) env('STOREFRONT_APPLE_DEVICE_LIMIT', 100),
    ],

    'devices' => [
        // Devices one customer account may enrol (product decision; see IMPLEMENTATION_PLAN §10).
        'max_per_account' => (int) env('STOREFRONT_MAX_DEVICES_PER_ACCOUNT', 1),
    ],

    'enrollment' => [
        'challenge_minutes' => 15,
        // Reverse-DNS identifier of the enrollment profile; placeholder until the bundle ID prefix is chosen (P0-04).
        'profile_identifier' => env('STOREFRONT_PROFILE_IDENTIFIER', 'invalid.storefront.enrollment'),
        // Sign the .mobileconfig with the site's TLS certificate so iOS shows it as verified (PEM paths).
        'signing_certificate' => env('STOREFRONT_PROFILE_SIGNING_CERT'),
        'signing_key' => env('STOREFRONT_PROFILE_SIGNING_KEY'),
        'signing_chain' => env('STOREFRONT_PROFILE_SIGNING_CHAIN'),
        // PEM file with Apple's device CA chain. When set, enrollment answers must be
        // signed by a genuine Apple device; otherwise only the signature itself is checked.
        'device_ca_file' => env('STOREFRONT_ENROLLMENT_DEVICE_CA'),
    ],

    'claims' => [
        'ttl_minutes' => 10,
        'url_scheme' => 'storefront',
    ],

    'catalog' => [
        // Stored media sizes (px). Icons are normalised to a square PNG; screenshots to JPEG.
        'icon_size' => 512,
        'screenshot_max_width' => 1290,
        'screenshot_max_count' => 10,
        'per_page_default' => 20,
        'per_page_max' => 50,
        'feed_section_limit' => 10,
    ],

];
