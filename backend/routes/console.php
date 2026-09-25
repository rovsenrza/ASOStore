<?php

use App\Jobs\SyncDeviceRegistrationsJob;
use App\Services\Installations\InstallationService;
use App\Services\Signing\SigningService;
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
