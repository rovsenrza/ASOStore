<?php

namespace App\Jobs;

use App\Models\DeviceRegistration;
use App\Services\Apple\AppleRetryableException;
use App\Services\Devices\DeviceRegistrationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Registers one device with Apple (FULL_PLAN §8.3). Idempotent: Apple is
 * asked for the device first, so a retry never registers it twice.
 */
class RegisterDeviceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $registrationId) {}

    public function uniqueId(): string
    {
        return (string) $this->registrationId;
    }

    public function handle(DeviceRegistrationService $registrations): void
    {
        $registration = DeviceRegistration::query()->find($this->registrationId);
        if ($registration === null) {
            return;
        }

        try {
            $registrations->register($registration);
        } catch (AppleRetryableException $e) {
            // Apple is rate-limiting or down: try again later, as Apple asked.
            $this->release($e->retryAfterSeconds);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $registration = DeviceRegistration::query()->find($this->registrationId);
        if ($registration !== null) {
            app(DeviceRegistrationService::class)->fail($registration, 'APPLE_UNAVAILABLE', 'Apple did not answer after repeated attempts.');
        }
    }
}
