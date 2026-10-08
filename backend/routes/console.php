<?php

use App\Jobs\SyncDeviceRegistrationsJob;
use App\Services\Artifacts\StorageJanitor;
use App\Services\Installations\InstallationService;
use App\Services\Operations\AlertEvaluator;
use App\Services\Operations\MetricsCollector;
use App\Services\Operations\RetentionService;
use App\Services\Quotas\QuotaReconciler;
use App\Services\Quotas\QuotaService;
use App\Services\Signing\BuildWarmup;
use App\Services\Signing\SigningService;
use App\Services\TelegramStore\Payments\PlategaPayments;
use Illuminate\Support\Facades\Schedule;

/*
| Runs from cron every minute: `php artisan schedule:run` (IMPLEMENTATION_PLAN D6).
*/

// Expired Sanctum access tokens.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Prunable models: expired refresh tokens and idempotency keys.
Schedule::command('model:prune')->daily();

// Expired password reset tokens.
Schedule::command('auth:clear-resets')->daily();

// Device registrations still processing at Apple, or waiting for an Apple team.
Schedule::job(new SyncDeviceRegistrationsJob)->everyFiveMinutes();

// Runner leases that ran out go back to the queue (IMPLEMENTATION_PLAN P6-BE-02).
Schedule::call(fn () => app(SigningService::class)->recoverExpiredLeases())
    ->name('signing:recover-leases')->everyMinute()->withoutOverlapping();

// Unused install links fall back to READY_TO_INSTALL (ExpireInstallTokenJob).
Schedule::call(fn () => app(InstallationService::class)->expireStaleAuthorizations())
    ->name('installations:expire-links')->everyMinute()->withoutOverlapping();

// Quota reconciliation against Apple and expiry alerts (IMPLEMENTATION_PLAN P7-BE-04).
Schedule::call(fn () => app(QuotaReconciler::class)->run())
    ->name('quota:reconcile')->dailyAt('03:30')->withoutOverlapping();

// Slot reservations whose Apple call never came back.
Schedule::call(fn () => app(QuotaService::class)->releaseExpired())
    ->name('quota:release-expired')->everyFiveMinutes()->withoutOverlapping();

// Metrics, alerts and retention (IMPLEMENTATION_PLAN P8-OPS-02, P8-SEC-02).
Schedule::call(fn () => app(MetricsCollector::class)->collect())
    ->name('metrics:collect')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn () => app(AlertEvaluator::class)->run())
    ->name('alerts:evaluate')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn () => app(RetentionService::class)->run())
    ->name('retention:apply')->dailyAt('04:00')->withoutOverlapping();

// Shared team builds: the most installed apps stay signed for every current device of each team.
Schedule::call(fn () => app(BuildWarmup::class)->forTeams())
    ->name('signing:presign-teams')->everyFifteenMinutes()->withoutOverlapping();

// Platega payments whose callback never arrived (store orders on the website and in the bot).
Schedule::call(fn () => app(PlategaPayments::class)->reconcile())
    ->name('platega:reconcile')->everyTwoMinutes()->withoutOverlapping();

// New and changed catalog pages go to Yandex/Bing via IndexNow (no-op without SEO_INDEXNOW_KEY);
// listings published since the last run also get their readable address first.
Schedule::command('catalog:seo-slugs')->dailyAt('05:00')->withoutOverlapping();
Schedule::command('seo:indexnow --hours=25')->dailyAt('05:10')->withoutOverlapping();

// The database cache never drops expired keys nobody reads again (worker nonces, throttles).
Schedule::command('cache:prune-database')->hourly()->withoutOverlapping();

// Idle signed builds, leftover objects and temporary copies, so storage does not pile up.
if (config('storefront.build_storage.janitor_enabled')) {
    Schedule::call(fn () => app(StorageJanitor::class)->run())
        ->name('storage:janitor')->everyTenMinutes()->withoutOverlapping(30);
}

// Shared-hosting queue mode (IMPLEMENTATION_PLAN D6): cron runs a short-lived worker every
// minute. Set STOREFRONT_SCHEDULED_QUEUE_WORKER=false when a supervised worker runs instead.
if (config('storefront.scheduled_queue_worker')) {
    Schedule::command('queue:work --queue=apple,default,files,background --stop-when-empty --max-time=50')
        ->name('queue:work-scheduled')->everyMinute()->withoutOverlapping()->runInBackground();
}
