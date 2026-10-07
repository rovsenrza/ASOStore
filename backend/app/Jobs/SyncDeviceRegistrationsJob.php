<?php

namespace App\Jobs;

use App\Enums\DeviceRegistrationStatus;
use App\Models\AppleTeam;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\MembershipYear;
use App\Services\Apple\AppleException;
use App\Services\Apple\AppleRetryableException;
use App\Services\Devices\DeviceRegistrationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled catch-up (routes/console.php):
 *  - polls Apple for registrations still processing;
 *  - moves devices Apple keeps processing to a team that enables them at once;
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

        $this->moveWaiting($registrations);

        DeviceRegistration::query()
            ->where('status', DeviceRegistrationStatus::Enrolled->value)
            ->where('updated_at', '<=', now()->subMinute())
            ->limit(100)
            ->pluck('id')
            ->each(fn (int $id) => RegisterDeviceJob::dispatch($id));

        $team = AppleTeam::primary();
        if ($team?->currentMembershipYear() === null) {
            return;
        }

        // A device may be on any team, not only the primary one.
        $years = MembershipYear::query()->where('status', 'ACTIVE')
            ->where('starts_at', '<=', now())->where('ends_at', '>', now())->pluck('id');
        Device::query()
            ->whereNotNull('enrolled_at')
            ->whereDoesntHave('registrations', fn ($query) => $query->whereIn('membership_year_id', $years))
            ->limit(100)
            ->get()
            ->each(function (Device $device) use ($registrations) {
                $registration = $registrations->request($device);
                if ($registration !== null) {
                    RegisterDeviceJob::dispatch($registration->id);
                }
            });
    }

    private function moveWaiting(DeviceRegistrationService $registrations): void
    {
        $minutes = (int) config('storefront.apple.move_waiting_after_minutes');
        if ($minutes <= 0) {
            return;
        }

        DeviceRegistration::query()
            ->where('status', DeviceRegistrationStatus::ApplePending->value)
            ->where('status_reason', 'APPLE_PROCESSING')
            ->where('registered_at', '<=', now()->subMinutes($minutes))
            ->orderBy('registered_at')
            ->limit(20)
            ->get()
            ->each(function (DeviceRegistration $registration) use ($registrations) {
                try {
                    $registrations->moveToInstantTeam($registration);
                } catch (AppleRetryableException) {
                    // Apple is busy; the next run tries again.
                } catch (AppleException $e) {
                    Log::warning('device_registration.move_failed', ['registration' => $registration->id, 'reason' => $e->reason]);
                }
            });
    }
}
