<?php

use App\Http\Controllers\Api\V1\Admin\ActivationCodeController;
use App\Http\Controllers\Api\V1\Admin\AdminAuthController;
use App\Http\Controllers\Api\V1\Admin\AppController as AdminAppController;
use App\Http\Controllers\Api\V1\Admin\AppMediaController;
use App\Http\Controllers\Api\V1\Admin\AppVersionController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\DeviceController as AdminDeviceController;
use App\Http\Controllers\Api\V1\Admin\TaxonomyController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Customer\ActivationController;
use App\Http\Controllers\Api\V1\Customer\AppController;
use App\Http\Controllers\Api\V1\Customer\AuthController;
use App\Http\Controllers\Api\V1\Customer\ClaimController;
use App\Http\Controllers\Api\V1\Customer\DeviceController;
use App\Http\Controllers\Api\V1\Customer\EnrollmentController;
use App\Http\Controllers\Api\V1\Customer\FeedController;
use App\Http\Controllers\Api\V1\Customer\StorefrontStatusController;
use App\Http\Controllers\Api\V1\Customer\TokenController;
use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
| API mounted at /api/v1 (bootstrap/app.php). Contract: docs/api/openapi.yaml.
| Middleware aliases: active = EnsureAccountActive, staff = RequireStaffSession,
| idempotent = Idempotent. Rate limiters live in AppServiceProvider.
*/

Route::get('/health', HealthController::class)->name('api.health');

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
    });
});

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/activation/redeem', [ActivationController::class, 'redeem'])
        ->middleware(['throttle:activation', 'idempotent'])
        ->name('api.activation.redeem');

    Route::get('/devices/me', [DeviceController::class, 'index'])->name('api.devices.me');
    Route::get('/devices/enrollment-profile', [EnrollmentController::class, 'profile'])
        ->middleware('throttle:enrollment')
        ->name('api.devices.enrollment-profile');
    Route::post('/storefront/claims', [ClaimController::class, 'store'])->middleware('throttle:claims')->name('api.storefront.claims');
});

// Called by iOS itself (Profile Service), authenticated by the one-time challenge in the URL.
Route::post('/devices/enrollment/callback', [EnrollmentController::class, 'callback'])
    ->middleware('throttle:enrollment-callback')
    ->name('api.devices.enrollment-callback');

// Called by the native app with the one-time code from storefront://claim.
Route::post('/storefront/claims/redeem', [ClaimController::class, 'redeem'])->middleware('throttle:claims')->name('api.storefront.claims.redeem');

// Storefront and catalog (public; install_state depends on the caller)
Route::prefix('storefront')->group(function () {
    Route::get('/feed', FeedController::class)->name('api.storefront.feed');
    Route::get('/status', StorefrontStatusController::class)->name('api.storefront.status');
});

Route::get('/apps', [AppController::class, 'index'])->name('api.apps.index');
Route::get('/apps/{app}', [AppController::class, 'show'])->name('api.apps.show');
Route::get('/apps/{app}/versions', [AppController::class, 'versions'])->name('api.apps.versions');

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
        Route::get('/apps/{app}', [AdminAppController::class, 'show'])->can('catalog.view')->name('apps.show');
        Route::patch('/apps/{app}', [AdminAppController::class, 'update'])->can('catalog.manage')->name('apps.update');
        Route::delete('/apps/{app}', [AdminAppController::class, 'destroy'])->can('catalog.manage')->name('apps.destroy');
        Route::post('/apps/{app}/restore', [AdminAppController::class, 'restore'])->can('catalog.manage')->name('apps.restore');
        Route::post('/apps/{app}/icon', [AppMediaController::class, 'icon'])->can('catalog.manage')->name('apps.icon');
        Route::post('/apps/{app}/screenshots', [AppMediaController::class, 'storeScreenshot'])->can('catalog.manage')->name('apps.screenshots.store');
        Route::put('/apps/{app}/screenshots/order', [AppMediaController::class, 'reorderScreenshots'])->can('catalog.manage')->name('apps.screenshots.order');
        Route::delete('/apps/{app}/screenshots/{screenshot}', [AppMediaController::class, 'destroyScreenshot'])->can('catalog.manage')->name('apps.screenshots.destroy');
        Route::post('/apps/{app}/versions', [AppVersionController::class, 'store'])->can('catalog.manage')->name('apps.versions.store');
        Route::patch('/app-versions/{version}', [AppVersionController::class, 'update'])->can('catalog.manage')->name('app-versions.update');
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
