<?php

namespace App\Services\Signing;

use App\Enums\DeviceRegistrationStatus;
use App\Exceptions\ApiException;
use App\Jobs\WarmBuildJob;
use App\Models\CatalogApp;
use App\Models\Certificate;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Services\Artifacts\StorageJanitor;
use App\Services\Installations\InstallationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

        foreach ($this->popularApps($limit) as $app) {
            $this->forApp($device, $app);
        }
    }

    /**
     * Keeps each team's most installed apps signed for all of its current devices, so the
     * first tap on them is instant too (builds shared by the team). Runs from the scheduler;
     * runners take these only when idle. Each team is represented by its newest eligible
     * device: the one an older team build is most likely to be missing.
     *
     * @return int builds started
     */
    public function forTeams(): int
    {
        $limit = max(0, min(30, (int) config('storefront.signing.team_presign_limit', 10)));
        if ($limit === 0 || ! config('storefront.signing.shared_builds', true) || ! config('storefront.signing.warmup_enabled', true)) {
            return 0;
        }

        $newest = DeviceRegistration::query()
            ->where('status', DeviceRegistrationStatus::Eligible->value)
            ->whereNotNull('apple_device_id')
            ->whereIn('id', DeviceRegistration::query()->selectRaw('MAX(id)')->groupBy('device_id'))
            ->orderByDesc('eligible_at')->orderByDesc('id')
            ->with('device.latestRegistration')
            ->get()
            ->unique('apple_team_id');
        $apps = $this->popularApps($limit);

        $started = 0;
        foreach ($newest as $registration) {
            $device = $registration->device;
            foreach ($apps as $app) {
                if ($device === null || ! $this->enabled($device)) {
                    break;
                }
                try {
                    $started += app(InstallationService::class)->prewarm($device, $app)->wasRecentlyCreated ? 1 : 0;
                } catch (ApiException) {
                    // Not installable on this device (iOS version, family): the next app.
                }
            }
        }

        return $started;
    }

    /**
     * Most installed over the last 30 days, then the featured order.
     *
     * @return Collection<int, CatalogApp>
     */
    private function popularApps(int $limit): Collection
    {
        return CatalogApp::query()->visibleToCustomers()
            ->where('is_storefront', false)->whereHas('publishedArtifact')
            ->with('publishedArtifact')
            ->withCount(['installations as recent_installations_count' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(30))])
            ->orderByDesc('recent_installations_count')
            ->orderByRaw('featured_rank IS NULL, featured_rank')->orderBy('id')
            ->limit($limit)->get();
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
