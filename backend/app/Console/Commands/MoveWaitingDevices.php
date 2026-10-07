<?php

namespace App\Console\Commands;

use App\Enums\DeviceRegistrationStatus;
use App\Models\DeviceRegistration;
use App\Services\Apple\AppleException;
use App\Services\Devices\DeviceRegistrationService;
use App\Services\Quotas\TeamSelector;
use Illuminate\Console\Command;

/**
 * Moves devices Apple keeps processing to a team that enables them at once. The scheduled
 * sync does the same after storefront.apple.move_waiting_after_minutes; this is for doing it
 * now, one device first, or with that setting off.
 */
class MoveWaitingDevices extends Command
{
    protected $signature = 'devices:move-waiting {--limit=100 : Most devices to move} {--dry-run : Only list them}';

    protected $description = 'Move devices waiting for Apple processing to a team that enables devices at once';

    public function handle(DeviceRegistrationService $registrations, TeamSelector $selector): int
    {
        $waiting = DeviceRegistration::query()->with(['device.user', 'team'])
            ->where('status', DeviceRegistrationStatus::ApplePending->value)
            ->where('status_reason', 'APPLE_PROCESSING')
            ->orderBy('registered_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();
        if ($waiting->isEmpty()) {
            $this->info('No device is waiting for Apple.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($waiting as $registration) {
            $label = sprintf('#%d %s (%s, waiting %d min)', $registration->id, $registration->device->maskedUdid(),
                $registration->device->user?->email ?? '-', (int) $registration->registered_at?->diffInMinutes(now()));
            if ($this->option('dry-run')) {
                $team = $selector->instantTeam($registration->device_family->value, exclude: $registration->apple_team_id);
                $this->line("{$label}: {$registration->team->apple_team_id} → ".($team?->apple_team_id ?? 'no team under the instant limit'));

                continue;
            }

            try {
                $next = $registrations->moveToInstantTeam($registration);
            } catch (AppleException $e) {
                $failed++;
                $this->error("{$label}: Apple refused ({$e->reason}), left where it was.");

                continue;
            }
            if ($next === null) {
                $this->warn("{$label}: not moved (no team under the instant limit, or no longer waiting).");

                continue;
            }
            $this->info("{$label}: {$registration->team->apple_team_id} → {$next->team->apple_team_id}, now {$next->status->value}".($next->status_reason ? " ({$next->status_reason})" : ''));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
