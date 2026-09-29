<?php

namespace App\Jobs;

use App\Models\AppArtifact;
use App\Models\Device;
use App\Services\Pipeline\RetryLater;
use App\Services\Signing\ProfileProvisioner;
use App\Services\Signing\SigningUnavailable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Makes the profiles for (artifact, device) while the customer reads the app page, so
 * the Apple calls are done before they tap «Установить». Best effort: the install
 * itself makes them again when this could not.
 */
class WarmProfilesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $artifactId, public readonly int $deviceId)
    {
        $this->onQueue(PipelineQueueJob::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->artifactId.':'.$this->deviceId;
    }

    public function handle(ProfileProvisioner $profiles): void
    {
        $artifact = AppArtifact::query()->find($this->artifactId);
        $device = Device::query()->find($this->deviceId);
        if ($artifact === null || $device === null) {
            return;
        }

        try {
            $profiles->ensure($artifact, $device);
        } catch (SigningUnavailable|RetryLater $e) {
            Log::info('signing.warm_skipped', ['artifact' => $artifact->public_id, 'device' => $device->public_id, 'reason' => $e->getMessage()]);
        }
    }
}
