<?php

use App\Jobs\SyncDeviceRegistrationsJob;
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
