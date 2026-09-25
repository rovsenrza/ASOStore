<?php

/*
 * Child process for tests/Concurrency: boots the app, waits for a shared start
 * time so every process hits MySQL together, then reserves one slot and
 * prints the outcome. Usage: php reserve-slot.php <registration id> <start unix time float>
 */

use App\Models\DeviceRegistration;
use App\Services\Quotas\QuotaService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$registrationId, $startAt] = [(int) $argv[1], (float) $argv[2]];
while (microtime(true) < $startAt) {
    usleep(500);
}

echo $app->make(QuotaService::class)->reserve(DeviceRegistration::query()->findOrFail($registrationId));
