<?php

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Services\Installations\InstallationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class WarmBuildJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $appId, public readonly int $artifactId, public readonly int $deviceId)
    {
        $this->onQueue('background');
    }

    public function uniqueId(): string
    {
        return $this->artifactId.':'.$this->deviceId;
    }

    public function handle(InstallationService $installations): void
    {
        $app = CatalogApp::query()->visibleToCustomers()->find($this->appId);
        $device = Device::query()->find($this->deviceId);
        if ($app === null || $device === null || $app->publishedArtifact?->id !== $this->artifactId
            || ! config('storefront.signing.warmup_enabled', true)) {
            return;
        }

        try {
            $installations->prewarm($device, $app);
        } catch (ApiException $e) {
            Log::info('signing.warm_build_skipped', ['app' => $app->public_id, 'device' => $device->public_id, 'reason' => $e->getMessage()]);
        }
    }
}
