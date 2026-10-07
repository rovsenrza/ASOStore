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
        // A new or recently renewed membership has Apple enable only its first 10 iOS devices at
        // registration; the rest wait 24–72 hours (developer.apple.com/help/account/reference/device-registration-updates).
        // New devices go to a team still under this count. 0 turns the routing off.
        'instant_device_limit' => (int) env('STOREFRONT_APPLE_INSTANT_DEVICE_LIMIT', 10),
        // A device Apple keeps processing longer than this moves to a team under the instant
        // limit (SyncDeviceRegistrationsJob). Instant devices also show PROCESSING for 1–5 minutes. 0 = off.
        'move_waiting_after_minutes' => (int) env('STOREFRONT_APPLE_MOVE_WAITING_AFTER_MINUTES', 30),
        // Ruby with fastlane, for App Groups through the developer portal (AppGroupProvisioner).
        'portal_ruby' => env('STOREFRONT_APPLE_PORTAL_RUBY', 'ruby'),
    ],

    'devices' => [
        // Devices one customer account may enrol (product decision; see IMPLEMENTATION_PLAN §10).
        'max_per_account' => (int) env('STOREFRONT_MAX_DEVICES_PER_ACCOUNT', 1),
    ],

    'enrollment' => [
        // Time between the profile download and Install in Settings; users on slow
        // networks took 14 minutes, so 15 left no margin.
        'challenge_minutes' => 60,
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

    // Customer self-import of IPAs (from Files or a link). Signed on the Apple certificate for one
    // device, so imports are capped per customer to protect the cert.
    'imports' => [
        'daily_limit' => (int) env('STOREFRONT_IMPORT_DAILY_LIMIT', 10),
        'total_limit' => (int) env('STOREFRONT_IMPORT_TOTAL_LIMIT', 30),
    ],

    'claims' => [
        'ttl_minutes' => 10,
        // The code embedded in the storefront build lives longer, to cover download and install
        // before the customer first opens the app.
        'bootstrap_ttl_minutes' => (int) env('STOREFRONT_BOOTSTRAP_TTL_MINUTES', 60),
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
        'file_cache_enabled' => (bool) env('STOREFRONT_ARTIFACT_CACHE_ENABLED', true),
        'file_cache_path' => storage_path('app/private/artifact-cache'),
        'file_cache_max_bytes' => (int) env('STOREFRONT_ARTIFACT_CACHE_MAX_BYTES', 8 * 1024 ** 3),
        'file_cache_ttl_seconds' => (int) env('STOREFRONT_ARTIFACT_CACHE_TTL_SECONDS', 86400),
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

    // One-step admin publishing (admin «Быстрая публикация»): an uploaded IPA is inspected, cleaned of
    // injected libraries, matched to (or turned into) a catalog listing, approved and published.
    // It uses the normal review service, so STOREFRONT_INDEPENDENT_REVIEW still makes it stop and wait.
    'quick_publish' => [
        'enabled' => (bool) env('STOREFRONT_QUICK_PUBLISH_ENABLED', true),
        'declaration_version' => '2026-10-quick-publish-v1',
        // Name, developer, category and icon from Apple's public lookup for a new listing.
        'lookup_enabled' => (bool) env('STOREFRONT_QUICK_PUBLISH_LOOKUP', true),
        'lookup_country' => env('STOREFRONT_QUICK_PUBLISH_LOOKUP_COUNTRY', 'ru'),
        'lookup_timeout' => (int) env('STOREFRONT_QUICK_PUBLISH_LOOKUP_TIMEOUT', 8),
        'max_bytes' => 5 * 1024 ** 3,
    ],

    'signing' => [
        // Re-prefix Info.plist values built from the vendor's team ID (e.g. a KeychainAccessGroup the app
        // uses as-is) with the signing team's, so they fall under its `<TEAM>.*` keychain access.
        'rewrite_team_prefix' => (bool) env('STOREFRONT_SIGNING_REWRITE_TEAM_PREFIX', true),
        // One build per app and Apple team, signed with a profile listing all of the team's eligible
        // devices, serves each of them: a later install of the same app starts at once. Every such IPA
        // lists the team's device UDIDs. Off: each device gets its own build (the storefront app always does).
        'shared_builds' => (bool) env('STOREFRONT_SIGNING_SHARED_BUILDS', true),
        'warmup_enabled' => (bool) env('STOREFRONT_SIGNING_WARMUP_ENABLED', true),
        // Popularity is measured from actual install requests over the last 30 days.
        'warmup_popular_limit' => (int) env('STOREFRONT_SIGNING_WARMUP_POPULAR_LIMIT', 3),
        // Shared builds: this many of the most installed apps are kept signed for each team's current
        // devices (BuildWarmup::forTeams, every 15 minutes, on idle runners). 0 turns it off.
        'team_presign_limit' => (int) env('STOREFRONT_SIGNING_TEAM_PRESIGN_LIMIT', 10),

        // Launch-compatibility shim injected into re-signed apps that share through an
        // App Group or keychain group (ios/compat-shim). Without it such apps quit on
        // launch because the vendor's original groups are not ours after re-signing.
        'compat_shim' => [
            'enabled' => (bool) env('STOREFRONT_SIGNING_COMPAT_SHIM', true),
            'path' => env('STOREFRONT_SIGNING_COMPAT_SHIM_PATH', base_path('../ios/compat-shim/RuStoreCompat.dylib')),
            // Apps (by the IPA's own bundle ID, `*` wildcards) whose servers check who is calling,
            // e.g. Yandex sign-in, which sends no SMS to an unknown app. They keep seeing their
            // original bundle ID inside the app (the shim answers with it); comma-separated.
            'keep_bundle_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('STOREFRONT_SIGNING_KEEP_BUNDLE_IDS', ''))))),
            // Apps (same matching) signed exactly as supplied, with no shim; comma-separated.
            'skip_bundle_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('STOREFRONT_SIGNING_COMPAT_SHIM_SKIP', ''))))),
        ],
    ],

    /*
    | A signed build is a per-device delivery copy of a published IPA: it can always be
    | signed again from the original. StorageJanitor removes idle ones so object storage
    | and the local disk do not fill up as devices × apps grow.
    */
    'build_storage' => [
        // Kill switch for the scheduled run; `artisan storage:janitor --dry-run` works either way.
        'janitor_enabled' => (bool) env('STOREFRONT_STORAGE_JANITOR_ENABLED', true),
        // Hard ceiling for all signed builds together; the least recently used go first.
        'budget_bytes' => (int) ((float) env('STOREFRONT_SIGNED_BUILD_BUDGET_GB', 30) * 1024 ** 3),
        // Idle time before removal: prepared ahead and never asked for / installed / waiting for the tap.
        'warm_idle_hours' => (int) env('STOREFRONT_WARM_BUILD_IDLE_HOURS', 12),
        'delivered_idle_hours' => (int) env('STOREFRONT_DELIVERED_BUILD_IDLE_HOURS', 24),
        'ready_idle_hours' => (int) env('STOREFRONT_READY_BUILD_IDLE_HOURS', 72),
        // A build used this recently is never removed, not even over budget (a download may be running).
        'in_use_minutes' => 30,
        // Speculative builds stop above this share of the budget, or past this many per device.
        'warmup_budget_ratio' => 0.7,
        'warm_builds_per_device' => (int) env('STOREFRONT_WARM_BUILDS_PER_DEVICE', 5),
        // Shared team builds: unused (warm) ones a team may hold, and how long an installed one is
        // kept idle (it serves every device of the team, so it is worth more than a per-device one).
        'warm_builds_per_team' => (int) env('STOREFRONT_WARM_BUILDS_PER_TEAM', 40),
        'shared_idle_hours' => (int) env('STOREFRONT_SHARED_BUILD_IDLE_HOURS', 168),
        // Originals of superseded versions go after this many days; a re-upload simply creates a new temporary copy.
        'superseded_original_days' => (int) env('STOREFRONT_SUPERSEDED_ORIGINAL_DAYS', 1),
        // Local caches and speculative builds stop below this share of free disk space.
        'min_free_disk_ratio' => (float) env('STOREFRONT_MIN_FREE_DISK_RATIO', 0.15),
        // Private temporary copies of IPAs; anything older than the stale age was left by a killed worker.
        'temp_path' => storage_path('app/private/tmp'),
        'temp_stale_hours' => 6,
    ],

    /*
    | catalog:clean-batch: which injected modules the unattended batch removes. A module goes
    | only when the analysis marks it removable, its file name matches `remove` and not `keep`.
    | `runtime` (a bundled Substrate) goes only when nothing else that could use it stays.
    | Names are fnmatch patterns, case-insensitive.
    */
    'catalog_clean' => [
        // Mod menus, cheat libraries and their sign-in/licence pop-ups: everything injected except `keep`.
        'remove' => ['*'],
        // Sideload fixes the game itself may need (checked on a device), and Roblox's Delta runtime.
        'keep' => ['masterSideloadFix*', 'dark.dylib', 'Sideloadbypass*', 'FixCrash*', 'Fixipa*', 'SatellaJailed*', 'libgloop*'],
        'runtime' => ['libsubstrate*'],
    ],

    /*
    | tools/ipa-cleaner: finds modules injected into supplied IPAs (promotional pop-ups,
    | channel gates, tweaks) during inspection, and makes cleaned copies on request.
    */
    'ipa_cleaner' => [
        'enabled' => (bool) env('STOREFRONT_IPA_CLEANER_ENABLED', true),
        'python' => env('STOREFRONT_IPA_CLEANER_PYTHON', 'python3'),
        'script' => env('STOREFRONT_IPA_CLEANER_SCRIPT', base_path('../tools/ipa-cleaner/ipa_clean.py')),
        'timeout' => (int) env('STOREFRONT_IPA_CLEANER_TIMEOUT', 900),
    ],

    // Defaults until the retention decision (IMPLEMENTATION_PLAN §10 Q8).
    'retention' => [
        'upload_session_hours' => (int) env('STOREFRONT_RETENTION_UPLOAD_HOURS', 24),
        'rejected_artifact_days' => (int) env('STOREFRONT_RETENTION_REJECTED_ARTIFACT_DAYS', 1),
        // Signed builds that can no longer be installed lose their file within the hour
        // (StorageJanitor); this is the fallback for a build it could not reach.
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
