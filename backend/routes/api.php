<?php

use App\Http\Controllers\Api\V1\Admin\ActivationCodeController;
use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\AppController as AdminAppController;
use App\Http\Controllers\Api\V1\Admin\AppleTeamController;
use App\Http\Controllers\Api\V1\Admin\AppMediaController;
use App\Http\Controllers\Api\V1\Admin\AppVersionController;
use App\Http\Controllers\Api\V1\Admin\ArtifactController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\DeviceController as AdminDeviceController;
use App\Http\Controllers\Api\V1\Admin\InstallationController as AdminInstallationController;
use App\Http\Controllers\Api\V1\Admin\JobController;
use App\Http\Controllers\Api\V1\Admin\OperationsController;
use App\Http\Controllers\Api\V1\Admin\QuickPublishController;
use App\Http\Controllers\Api\V1\Admin\RunnerController;
use App\Http\Controllers\Api\V1\Admin\TaxonomyController;
use App\Http\Controllers\Api\V1\Admin\TeamAssignmentController;
use App\Http\Controllers\Api\V1\Admin\TeamEligibilityController;
use App\Http\Controllers\Api\V1\Admin\UploadController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Customer\ActivationController;
use App\Http\Controllers\Api\V1\Customer\AppController;
use App\Http\Controllers\Api\V1\Customer\AuthController;
use App\Http\Controllers\Api\V1\Customer\ClaimController;
use App\Http\Controllers\Api\V1\Customer\DeviceController;
use App\Http\Controllers\Api\V1\Customer\EmailVerificationController;
use App\Http\Controllers\Api\V1\Customer\EnrollmentController;
use App\Http\Controllers\Api\V1\Customer\FeedController;
use App\Http\Controllers\Api\V1\Customer\ImportController;
use App\Http\Controllers\Api\V1\Customer\InstallationController;
use App\Http\Controllers\Api\V1\Customer\InstallDeliveryController;
use App\Http\Controllers\Api\V1\Customer\StorefrontStatusController;
use App\Http\Controllers\Api\V1\Customer\SupportController;
use App\Http\Controllers\Api\V1\Customer\TokenController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\StoreOfferController;
use App\Http\Controllers\Api\V1\StoreOrderController;
use App\Http\Middleware\RejectStaleBearerToken;
use Illuminate\Support\Facades\Route;

/*
| API mounted at /api/v1 (bootstrap/app.php). Contract: docs/api/openapi.yaml.
| Middleware aliases: active = EnsureAccountActive, staff = RequireStaffSession,
| idempotent = Idempotent. Rate limiters live in AppServiceProvider.
*/

Route::get('/health', HealthController::class)->name('api.health');

// Plans, prices and payment options for the website's purchase page.
Route::get('/store/offer', StoreOfferController::class)->name('api.store.offer');
// Order state for the payment result page (website and bot orders); the ULID is the credential.
Route::get('/store/orders/{order}', [StoreOrderController::class, 'show'])->middleware('throttle:store-order')->name('api.store.orders.show');
// Called by Platega, authenticated by the merchant ID and secret it sends back.
Route::post('/payments/platega/callback', [StoreOrderController::class, 'callback'])->middleware('throttle:payment-callback')->name('api.payments.platega.callback');

// Customer authentication
Route::prefix('auth')->name('api.auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login')->name('login');
    Route::post('/tokens', [TokenController::class, 'store'])->middleware('throttle:auth-login')->name('tokens');
    Route::post('/refresh', [TokenController::class, 'refresh'])->middleware('throttle:auth-refresh')->name('refresh');
    Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-forgot')->name('password.forgot');
    Route::post('/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset')->name('password.reset');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/email/verify', [EmailVerificationController::class, 'verify'])->middleware('throttle:email-verify')->name('email.verify');
        Route::post('/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:email-resend')->name('email.resend');
    });
});

Route::middleware(['auth:sanctum', 'active', 'verified.web'])->group(function () {
    Route::post('/activation/redeem', [ActivationController::class, 'redeem'])
        ->middleware(['throttle:activation', 'idempotent'])
        ->name('api.activation.redeem');
    // «Оплатить» on the website: a store order for this account, paid on Platega's page.
    Route::post('/store/checkout', [StoreOrderController::class, 'checkout'])
        ->middleware(['throttle:store-checkout', 'idempotent'])
        ->name('api.store.checkout');

    Route::get('/devices/me', [DeviceController::class, 'index'])->name('api.devices.me');
    Route::get('/devices/enrollment-profile', [EnrollmentController::class, 'profile'])
        ->middleware('throttle:enrollment')
        ->name('api.devices.enrollment-profile');
    Route::post('/storefront/claims', [ClaimController::class, 'store'])->middleware('throttle:claims')->name('api.storefront.claims');

    // Preparation and installation (IMPLEMENTATION_PLAN §5.6, P6-BE-03)
    Route::post('/apps/{app}/prepare', [InstallationController::class, 'prepare'])->middleware(['throttle:installs', 'idempotent'])->name('api.apps.prepare');
    Route::post('/storefront/install', [InstallationController::class, 'installStorefront'])->middleware(['throttle:installs', 'idempotent'])->name('api.storefront.install');
    Route::get('/installations/{installation}', [InstallationController::class, 'show'])->name('api.installations.show');
    Route::post('/installations/{installation}/authorize', [InstallationController::class, 'authorize'])->middleware(['throttle:installs', 'idempotent'])->name('api.installations.authorize');
    Route::get('/library', [InstallationController::class, 'library'])->name('api.library');

    // Customer self-import of an IPA from Files (owner-only; hidden from the public catalog).
    Route::get('/imports', [ImportController::class, 'index'])->name('api.imports.index');
    Route::post('/imports', [ImportController::class, 'store'])->middleware('throttle:imports')->name('api.imports.store');
    Route::post('/imports/link', [ImportController::class, 'link'])->middleware('throttle:imports')->name('api.imports.link');
    Route::put('/imports/{upload}/chunks/{number}', [ImportController::class, 'chunk'])->whereNumber('number')->name('api.imports.chunk');
    Route::post('/imports/{upload}/complete', [ImportController::class, 'complete'])->middleware('throttle:imports')->name('api.imports.complete');
    Route::post('/imports/{import}/install', [ImportController::class, 'install'])->middleware(['throttle:installs', 'idempotent'])->name('api.imports.install');
    Route::delete('/imports/{import}', [ImportController::class, 'destroy'])->middleware('throttle:imports')->name('api.imports.destroy');
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // The customer's own data (IMPLEMENTATION_PLAN P8-SEC-02), also before the email is confirmed.
    Route::get('/account/export', [SupportController::class, 'export'])->middleware('throttle:account-data')->name('api.account.export');
    Route::post('/account/deletion-request', [SupportController::class, 'requestDeletion'])->middleware('throttle:account-data')->name('api.account.deletion-request');
});

// Support requests, also from signed-out visitors (IMPLEMENTATION_PLAN P8-WEB-01).
Route::post('/support/tickets', [SupportController::class, 'store'])->middleware('throttle:support')->name('api.support.tickets');

// Fetched by iOS during the OTA install: the token or the URL signature is the credential.
Route::get('/install/{token}/manifest.plist', [InstallDeliveryController::class, 'manifest'])->middleware('throttle:install-manifest')->name('api.install.manifest');
Route::get('/downloads/installations/{installation}', [InstallDeliveryController::class, 'download'])->middleware('throttle:install-manifest')->name('api.downloads.installation');

// Called by iOS itself (Profile Service), authenticated by the one-time challenge in the URL.
Route::post('/devices/enrollment/callback', [EnrollmentController::class, 'callback'])
    ->middleware('throttle:enrollment-callback')
    ->name('api.devices.enrollment-callback');

// Called by the native app with the one-time code from storefront://claim.
Route::post('/storefront/claims/redeem', [ClaimController::class, 'redeem'])->middleware('throttle:claims')->name('api.storefront.claims.redeem');

// Storefront and catalog (public; install_state depends on the caller, so a stale token gets 401)
Route::middleware(RejectStaleBearerToken::class)->group(function () {
    Route::prefix('storefront')->group(function () {
        Route::get('/feed', FeedController::class)->name('api.storefront.feed');
        Route::get('/status', StorefrontStatusController::class)->name('api.storefront.status');
    });

    Route::get('/apps', [AppController::class, 'index'])->name('api.apps.index');
    Route::get('/apps/{app}', [AppController::class, 'show'])->name('api.apps.show');
    Route::get('/apps/{app}/versions', [AppController::class, 'versions'])->name('api.apps.versions');
});

// Admin: browser session + staff role + TOTP (IMPLEMENTATION_PLAN D4, §5.9)
Route::prefix('admin')->name('api.admin.')->group(function () {
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login')->name('login');
        Route::post('/totp', [AdminAuthController::class, 'totp'])->middleware('throttle:admin-totp')->name('totp');
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('/me', [AdminAuthController::class, 'me'])->middleware(['auth:sanctum', 'active', 'staff'])->name('me');
    });

    Route::middleware(['auth:sanctum', 'active', 'staff'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->can('users.view')->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->can('users.manage')->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->can('users.view')->name('users.show');
        Route::patch('/users/{user}', [UserController::class, 'update'])->can('users.manage')->name('users.update');
        Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])->can('users.manage')->name('users.roles');
        Route::post('/users/{user}/totp/reset', [UserController::class, 'resetTotp'])->can('users.manage')->name('users.totp.reset');

        Route::get('/activation-codes', [ActivationCodeController::class, 'index'])->can('activation-codes.view')->name('activation-codes.index');
        Route::post('/activation-codes', [ActivationCodeController::class, 'store'])->can('activation-codes.manage')->middleware('idempotent')->name('activation-codes.store');
        Route::post('/activation-codes/{activationCode}/revoke', [ActivationCodeController::class, 'revoke'])->can('activation-codes.manage')->name('activation-codes.revoke');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->can('audit.view')->name('audit-logs.index');

        Route::get('/apps', [AdminAppController::class, 'index'])->can('catalog.view')->name('apps.index');
        Route::post('/apps', [AdminAppController::class, 'store'])->can('catalog.manage')->name('apps.store');
        Route::post('/apps/import', [AdminAppController::class, 'import'])->can('catalog.manage')->middleware('throttle:30,1')->name('apps.import');
        Route::get('/apps/{app}', [AdminAppController::class, 'show'])->can('catalog.view')->name('apps.show');
        Route::patch('/apps/{app}', [AdminAppController::class, 'update'])->can('catalog.manage')->name('apps.update');
        Route::delete('/apps/{app}', [AdminAppController::class, 'destroy'])->can('catalog.manage')->name('apps.destroy');
        Route::post('/apps/{app}/restore', [AdminAppController::class, 'restore'])->can('catalog.manage')->name('apps.restore');
        Route::post('/apps/{app}/icon', [AppMediaController::class, 'icon'])->can('catalog.manage')->name('apps.icon');
        Route::post('/apps/{app}/banner', [AppMediaController::class, 'banner'])->can('catalog.manage')->name('apps.banner');
        Route::delete('/apps/{app}/banner', [AppMediaController::class, 'destroyBanner'])->can('catalog.manage')->name('apps.banner.destroy');
        Route::post('/apps/{app}/screenshots', [AppMediaController::class, 'storeScreenshot'])->can('catalog.manage')->name('apps.screenshots.store');
        Route::put('/apps/{app}/screenshots/order', [AppMediaController::class, 'reorderScreenshots'])->can('catalog.manage')->name('apps.screenshots.order');
        Route::delete('/apps/{app}/screenshots/{screenshot}', [AppMediaController::class, 'destroyScreenshot'])->can('catalog.manage')->name('apps.screenshots.destroy');
        Route::post('/apps/{app}/versions', [AppVersionController::class, 'store'])->can('catalog.manage')->name('apps.versions.store');
        Route::patch('/app-versions/{version}', [AppVersionController::class, 'update'])->can('catalog.manage')->name('app-versions.update');
        Route::post('/uploads', [UploadController::class, 'store'])->can('artifacts.manage')->name('uploads.store');
        Route::get('/uploads/{upload}', [UploadController::class, 'show'])->can('artifacts.view')->name('uploads.show');
        Route::put('/uploads/{upload}/chunks/{number}', [UploadController::class, 'chunk'])->can('artifacts.manage')->whereNumber('number')->name('uploads.chunks.store');
        Route::post('/uploads/{upload}/complete', [UploadController::class, 'complete'])->can('artifacts.manage')->name('uploads.complete');
        // One-step publishing: upload → inspect → clean → listing → approve → publish. Needs both abilities.
        Route::get('/quick-publish', [QuickPublishController::class, 'index'])->can('artifacts.view')->name('quick-publish.index');
        Route::post('/quick-publish/uploads', [QuickPublishController::class, 'start'])->can('artifacts.manage')->can('catalog.manage')->name('quick-publish.start');
        Route::post('/quick-publish/uploads/{upload}/complete', [QuickPublishController::class, 'complete'])->can('artifacts.manage')->can('catalog.manage')->name('quick-publish.complete');
        Route::get('/quick-publish/{job}', [QuickPublishController::class, 'show'])->can('artifacts.view')->name('quick-publish.show');
        Route::post('/quick-publish/{job}/resume', [QuickPublishController::class, 'resume'])->can('artifacts.manage')->can('catalog.manage')->name('quick-publish.resume');
        Route::get('/artifacts', [ArtifactController::class, 'index'])->can('artifacts.view')->name('artifacts.index');
        Route::get('/artifacts/{artifact}', [ArtifactController::class, 'show'])->can('artifacts.view')->name('artifacts.show');
        Route::post('/artifacts/{artifact}/review', [ArtifactController::class, 'review'])->can('artifacts.manage')->middleware('idempotent')->name('artifacts.review');
        Route::post('/artifacts/{artifact}/publish', [ArtifactController::class, 'publish'])->can('artifacts.manage')->middleware('idempotent')->name('artifacts.publish');
        Route::post('/artifacts/{artifact}/revoke', [ArtifactController::class, 'revoke'])->can('artifacts.manage')->middleware('idempotent')->name('artifacts.revoke');
        Route::post('/artifacts/{artifact}/inspect', [ArtifactController::class, 'inspect'])->can('artifacts.manage')->middleware('idempotent')->name('artifacts.inspect');
        Route::post('/artifacts/{artifact}/clean', [ArtifactController::class, 'clean'])->can('artifacts.manage')->middleware('idempotent')->name('artifacts.clean');
        Route::post('/artifacts/{artifact}/documents', [ArtifactController::class, 'storeDocument'])->can('artifacts.manage')->name('artifacts.documents.store');
        Route::get('/artifacts/{artifact}/documents/{document}', [ArtifactController::class, 'showDocument'])->can('artifacts.view')->name('artifacts.documents.show');
        Route::get('/jobs', [JobController::class, 'index'])->can('jobs.view')->name('jobs.index');
        Route::get('/jobs/{job}', [JobController::class, 'show'])->can('jobs.view')->name('jobs.show');
        Route::post('/jobs/{job}/retry', [JobController::class, 'retry'])->can('jobs.manage')->middleware('idempotent')->name('jobs.retry');
        Route::get('/runners', [RunnerController::class, 'index'])->can('jobs.view')->name('runners.index');
        Route::patch('/runners/{runner}', [RunnerController::class, 'update'])->can('teams.manage')->name('runners.update');
        Route::get('/apple-teams', [AppleTeamController::class, 'index'])->can('teams.view')->name('apple-teams.index');
        Route::post('/apple-teams', [AppleTeamController::class, 'store'])->can('teams.manage')->name('apple-teams.store');
        Route::patch('/apple-teams/{team}', [AppleTeamController::class, 'update'])->can('teams.manage')->name('apple-teams.update');
        Route::post('/apple-teams/{team}/credentials', [AppleTeamController::class, 'storeCredential'])->can('teams.manage')->name('apple-teams.credentials');
        Route::post('/apple-teams/{team}/verify', [AppleTeamController::class, 'verify'])->can('teams.manage')->name('apple-teams.verify');
        Route::post('/apple-teams/{team}/membership-years', [AppleTeamController::class, 'storeMembershipYear'])->can('teams.manage')->name('apple-teams.membership-years');
        Route::post('/apple-teams/{team}/sync', [AppleTeamController::class, 'sync'])->can('teams.manage')->name('apple-teams.sync');
        Route::get('/team-eligibilities', [TeamEligibilityController::class, 'index'])->can('teams.view')->name('team-eligibilities.index');
        Route::post('/team-eligibilities', [TeamEligibilityController::class, 'store'])->can('teams.manage')->name('team-eligibilities.store');
        Route::post('/team-eligibilities/{eligibility}/revoke', [TeamEligibilityController::class, 'revoke'])->can('teams.manage')->name('team-eligibilities.revoke');
        Route::get('/quota-assignments', [TeamAssignmentController::class, 'index'])->can('teams.view')->name('quota-assignments.index');
        Route::post('/quota-assignments/{assignment}/approve', [TeamAssignmentController::class, 'approve'])->can('teams.manage')->middleware('idempotent')->name('quota-assignments.approve');
        Route::post('/quota-assignments/{assignment}/reject', [TeamAssignmentController::class, 'reject'])->can('teams.manage')->middleware('idempotent')->name('quota-assignments.reject');
        Route::get('/metrics', [OperationsController::class, 'metrics'])->can('jobs.view')->name('metrics');
        Route::get('/storage', [OperationsController::class, 'storage'])->can('jobs.view')->name('storage');
        Route::get('/support-tickets', [OperationsController::class, 'tickets'])->can('users.view')->name('support-tickets.index');
        Route::post('/support-tickets/{ticket}/close', [OperationsController::class, 'closeTicket'])->can('users.view')->name('support-tickets.close');
        Route::post('/users/{user}/erase', [OperationsController::class, 'erase'])->can('users.manage')->middleware('idempotent')->name('users.erase');
        Route::get('/installations', [AdminInstallationController::class, 'index'])->can('installations.view')->name('installations.index');
        Route::get('/installations/{installation}', [AdminInstallationController::class, 'show'])->can('installations.view')->name('installations.show');
        Route::get('/categories', [TaxonomyController::class, 'categories'])->can('catalog.view')->name('categories.index');
        Route::post('/categories', [TaxonomyController::class, 'storeCategory'])->can('catalog.manage')->name('categories.store');
        Route::patch('/categories/{category}', [TaxonomyController::class, 'updateCategory'])->can('catalog.manage')->name('categories.update');
        Route::get('/publishers', [TaxonomyController::class, 'publishers'])->can('catalog.view')->name('publishers.index');
        Route::post('/publishers', [TaxonomyController::class, 'storePublisher'])->can('catalog.manage')->name('publishers.store');
        Route::patch('/publishers/{publisher}', [TaxonomyController::class, 'updatePublisher'])->can('catalog.manage')->name('publishers.update');

        Route::get('/devices', [AdminDeviceController::class, 'index'])->can('devices.view')->name('devices.index');
        Route::get('/devices/{device}', [AdminDeviceController::class, 'show'])->can('devices.view')->name('devices.show');
        Route::post('/devices/{device}/sync', [AdminDeviceController::class, 'sync'])->can('devices.manage')->name('devices.sync');
        Route::post('/devices/{device}/reveal-udid', [AdminDeviceController::class, 'revealUdid'])->can('devices.reveal-udid')->name('devices.reveal-udid');
    });
});
