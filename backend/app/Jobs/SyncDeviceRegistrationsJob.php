<?php

namespace App\Jobs;

use App\Enums\DeviceRegistrationStatus;
use App\Models\AppleTeam;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Services\Apple\AppleRetryableException;
use App\Services\Devices\DeviceRegistrationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled catch-up (routes/console.php):
 *  - polls Apple for registrations still processing;
 *  - starts registrations for devices enrolled while no Apple team was
 *    connected, or while the driver was disabled.
 */
class SyncDeviceRegistrationsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // A worker killed mid-run would otherwise keep the unique lock forever and stop the sync.
    public int $uniqueFor = 300;

    public function handle(DeviceRegistrationService $registrations): void
    {
        DeviceRegistration::query()
            ->where('status', DeviceRegistrationStatus::ApplePending->value)
            ->whereNotNull('apple_device_id')
            ->where(fn ($query) => $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<=', now()->subMinute()))
            ->limit(100)
            ->get()
            ->each(function (DeviceRegistration $registration) use ($registrations) {
                try {
                    $registrations->sync($registration);
                } catch (AppleRetryableException) {
                    // Apple is busy; the next run tries again.
                }
            });

        DeviceRegistration::query()
            ->where('status', DeviceRegistrationStatus::Enrolled->value)
            ->where('updated_at', '<=', now()->subMinute())
            ->limit(100)
            ->pluck('id')
            ->each(fn (int $id) => RegisterDeviceJob::dispatch($id));

        $team = AppleTeam::primary();
        $year = $team?->currentMembershipYear();
        if ($year === null) {
            return;
        }

        Device::query()
            ->whereNotNull('enrolled_at')
            ->whereDoesntHave('registrations', fn ($query) => $query->where('membership_year_id', $year->id))
            ->limit(100)
            ->get()
            ->each(function (Device $device) use ($registrations) {
                $registration = $registrations->request($device);
                if ($registration !== null) {
                    RegisterDeviceJob::dispatch($registration->id);
                }
            });
    }
}
