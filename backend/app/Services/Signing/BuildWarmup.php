<?php

namespace App\Services\Signing;

use App\Enums\DeviceRegistrationStatus;
use App\Jobs\WarmBuildJob;
use App\Models\CatalogApp;
use App\Models\Certificate;
use App\Models\Device;
use App\Services\Artifacts\StorageJanitor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/** Starts bounded, speculative builds without recording a customer installation. */
final class BuildWarmup
{
    public function forDevice(Device $device): void
    {
        if (! $this->enabled($device)) {
            return;
        }

        $limit = max(0, min(10, (int) config('storefront.signing.warmup_popular_limit', 3)));
        if ($limit === 0) {
            return;
        }

        $apps = CatalogApp::query()->visibleToCustomers()
            ->where('is_storefront', false)->whereHas('publishedArtifact')
            ->with('publishedArtifact')
            ->withCount(['installations as recent_installations_count' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(30))])
            ->orderByDesc('recent_installations_count')
            ->orderByRaw('featured_rank IS NULL, featured_rank')->orderBy('id')
            ->limit($limit)->get();

        foreach ($apps as $app) {
            $this->forApp($device, $app);
        }
    }

    public function forApp(Device $device, CatalogApp $app): void
    {
        $artifact = $app->publishedArtifact;
        if (! $this->enabled($device) || $artifact === null) {
            return;
        }

        // A new version has a different key. An install still checks profile/certificate expiry.
        $key = "warm-build:{$artifact->id}:{$device->id}";
        if (Cache::add($key, true, now()->addMinutes(10))) {
            WarmBuildJob::dispatch($app->id, $artifact->id, $device->id)->afterCommit();
        }
    }

    private function enabled(Device $device): bool
    {
        return config('storefront.signing.warmup_enabled', true)
            && $device->latestRegistration?->status === DeviceRegistrationStatus::Eligible
            && Certificate::query()->where('apple_team_id', $device->latestRegistration->apple_team_id)
                ->where('status', 'ACTIVE')->whereNotNull('runner_id')
                ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()->addDay()))
                ->exists()
            // Speculative builds must not be what fills the storage.
            && app(StorageJanitor::class)->allowsWarmup($device);
    }
}
