<?php

return [

    /*
    | Visible product brand (PRODUCT.md).
    */
    'brand' => env('APP_NAME', 'Ru App Store'),

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

    'scheduled_queue_worker' => (bool) env('STOREFRONT_SCHEDULED_QUEUE_WORKER', true),

    'security' => [
        'csp_report_only' => (bool) env('STOREFRONT_CSP_REPORT_ONLY', false),
        'hsts_max_age' => (int) env('STOREFRONT_HSTS_MAX_AGE', 31536000),
    ],

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
        // Ruby with fastlane, for App Groups through the developer portal (AppGroupProvisioner).
        'portal_ruby' => env('STOREFRONT_APPLE_PORTAL_RUBY', 'ruby'),
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
        // Stored media sizes (px). Icons and banners are small WebP files; screenshots are JPEG.
        // An icon shows at up to about 110 pt (330 px at 3x), a banner at one phone width.
        'icon_size' => 384,
        'icon_quality' => 88,
        'banner_max_width' => 1280,
        'banner_quality' => 80,
        'screenshot_max_width' => 1290,
        'screenshot_max_count' => 10,
        'per_page_default' => 20,
        'per_page_max' => 50,
        'feed_section_limit' => 10,
    ],

    'artifacts' => [
        // Source types that may be published (FULL_PLAN §5.1). Narrow this to match the
        // P0-01 distribution-channel determination, e.g. "OWN_BUILD" for the pilot.
        'publishable_source_types' => array_values(array_filter(explode(',', (string) env(
            'STOREFRONT_PUBLISHABLE_SOURCE_TYPES',
            'OWN_BUILD,PARTNER_BUILD,OPEN_SOURCE_BUILD,ALTERNATIVE_MARKETPLACE_PACKAGE,USER_IMPORT,CUSTOMER_PROVIDED',
        )))),
        // Require an approved team_app_eligibilities row for the bundle ID before an
        // artifact can pass COMPATIBILITY_CHECK or be signed (IMPLEMENTATION_PLAN P7-BE-03).
        'require_team_eligibility' => (bool) env('STOREFRONT_REQUIRE_TEAM_ELIGIBILITY', true),
        // Signing bundle IDs under this prefix are ours: a listing that carries one is
        // approved for the primary team automatically (TeamEligibilityGranter).
        'own_bundle_prefix' => env('STOREFRONT_OWN_BUNDLE_PREFIX', 'com.ruappstore.'),
        // When true, the uploader of an artifact cannot approve it (four-eyes review).
        'independent_review' => (bool) env('STOREFRONT_INDEPENDENT_REVIEW', false),
        // Provenance review checklist (P0-02). Every item must be confirmed to approve;
        // bump the version whenever the wording the reviewer sees changes.
        'review_checklist_version' => '2026-09-v1',
        'review_checklist' => ['source_verified', 'distribution_rights_confirmed', 'inspection_report_reviewed'],
        'document_max_kilobytes' => 20 * 1024,
        'document_mimes' => ['pdf', 'png', 'jpg', 'jpeg', 'txt'],
    ],

    // Defaults until the retention decision (IMPLEMENTATION_PLAN §10 Q8).
    'retention' => [
        'upload_session_hours' => (int) env('STOREFRONT_RETENTION_UPLOAD_HOURS', 24),
        'rejected_artifact_days' => (int) env('STOREFRONT_RETENTION_REJECTED_ARTIFACT_DAYS', 90),
        'signed_build_days' => (int) env('STOREFRONT_RETENTION_SIGNED_BUILD_DAYS', 30),
        'installation_event_days' => (int) env('STOREFRONT_RETENTION_INSTALLATION_EVENT_DAYS', 365),
    ],

    // Alert thresholds (FULL_PLAN §14, IMPLEMENTATION_PLAN P8-OPS-02).
    'alerts' => [
        'quota_remaining_ratio' => 0.1,
        'signing_failures_per_hour' => 3,
        'queue_backlog' => (int) env('STOREFRONT_ALERT_QUEUE_BACKLOG', 50),
        'storage_free_ratio' => 0.1,
        'downloads_per_user_per_hour' => 30,
        'enrollments_per_ip_per_hour' => 10,
        'runner_offline_minutes' => 5,
        'backup_max_age_hours' => 26,
        // Where scripts/backup.sh writes its result (storage/app/backup-status.json).
        'backup_status_file' => env('STOREFRONT_BACKUP_STATUS_FILE', storage_path('app/backup-status.json')),
    ],

    // IPA inspection limits (IMPLEMENTATION_PLAN §5.7). Archives are untrusted.
    'inspection' => [
        'max_entries' => (int) env('STOREFRONT_IPA_MAX_ENTRIES', 100000),
        'max_uncompressed_bytes' => (int) env('STOREFRONT_IPA_MAX_UNCOMPRESSED_BYTES', 16 * 1024 ** 3),
        // Zip-bomb guard: entries (and the archive) above ratio_min_bytes may not expand more than this.
        'max_compression_ratio' => (int) env('STOREFRONT_IPA_MAX_COMPRESSION_RATIO', 200),
        'ratio_min_bytes' => 16 * 1024 ** 2,
        'max_binary_bytes' => (int) env('STOREFRONT_IPA_MAX_BINARY_BYTES', 2 * 1024 ** 3),
        'max_plist_bytes' => 4 * 1024 ** 2,
        // Absolute path to clamdscan; without it scans are reported as SCAN_UNAVAILABLE.
        'clamdscan_path' => env('STOREFRONT_CLAMDSCAN_PATH'),
        'clamdscan_timeout' => 900,
    ],

];
