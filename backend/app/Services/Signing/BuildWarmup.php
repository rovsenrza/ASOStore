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
        $limit = max(0, min(100, (int) config('storefront.signing.team_presign_limit', 10)));
        if (! config('storefront.signing.shared_builds', true) || ! config('storefront.signing.warmup_enabled', true)) {
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

        // One app at a time across all teams, not all apps for one team: the runner keeps the app it
        // has just signed unpacked and cached, so the next team's build of it skips the download and the
        // unpacking (a multi-gigabyte app thrown out by another one makes every build start from zero).
        $started = 0;
        $wholeBuildsAllowed = [];
        foreach ($apps as $app) {
            $delta = $this->isDelta($app);
            foreach ($newest as $registration) {
                $device = $registration->device;
                $team = (int) $registration->apple_team_id;
                if ($device === null || (! $delta && ! ($wholeBuildsAllowed[$team] ?? true))) {
                    continue;
                }
                // The first refusal ends the whole builds for a team (they share the budget); a delta
                // build only has the disk rule to meet, so the ones after it are still tried.
                if (! $this->enabled($device, $delta)) {
                    if (! $delta) {
                        $wholeBuildsAllowed[$team] = false;
                    }

                    continue;
                }
                try {
                    // Only a team build counts: the device's own (older, per-device) build serves no one else.
                    $started += app(InstallationService::class)->prewarm($device, $app, sharedOnly: true)->wasRecentlyCreated ? 1 : 0;
                } catch (ApiException) {
                    // Not installable on this device (iOS version, family): the next app.
                }
            }
        }

        return $started;
    }

    /**
     * Most installed over the last 30 days, then the featured order; then every app that goes out as a
     * delta build, whatever its popularity.
     *
     * @return Collection<int, CatalogApp>
     */
    private function popularApps(int $limit): Collection
    {
        $popular = $limit === 0 ? new Collection : CatalogApp::query()->visibleToCustomers()
            ->where('is_storefront', false)->whereHas('publishedArtifact')
            ->with('publishedArtifact')
            ->withCount(['installations as recent_installations_count' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(30))])
            ->orderByDesc('recent_installations_count')
            ->orderByRaw('featured_rank IS NULL, featured_rank')->orderBy('id')
            ->limit($limit)->get();

        return $popular->concat($this->deltaApps($popular->modelKeys()))->values();
    }

    /**
     * Apps big enough to be delivered as delta builds (docs/adr/DELTA_BUILDS.md). A team's build of one
     * stores only what re-signing changes, so having it ready for every team costs megabytes, and a
     * customer's first tap never waits for a multi-gigabyte app to be signed.
     *
     * @param  list<int>  $except
     * @return Collection<int, CatalogApp>
     */
    private function deltaApps(array $except): Collection
    {
        if (! config('storefront.signing.delta_builds.enabled')) {
            return new Collection;
        }
        $minimum = (int) config('storefront.signing.delta_builds.min_bytes');

        return CatalogApp::query()->visibleToCustomers()
            ->where('is_storefront', false)->whereNotIn('id', $except)
            ->whereHas('publishedArtifact', fn (Builder $query) => $query->where('size_bytes', '>=', $minimum))
            ->with('publishedArtifact')
            ->orderBy('id')->limit(20)->get();
    }

    public function forApp(Device $device, CatalogApp $app): void
    {
        $artifact = $app->publishedArtifact;
        if ($artifact === null || ! $this->enabled($device, $this->isDelta($app))) {
            return;
        }

        // A new version has a different key. An install still checks profile/certificate expiry.
        $key = "warm-build:{$artifact->id}:{$device->id}";
        if (Cache::add($key, true, now()->addMinutes(10))) {
            WarmBuildJob::dispatch($app->id, $artifact->id, $device->id)->afterCommit();
        }
    }

    /** Whether the app goes out as delta builds, so a build of it costs megabytes, not its full size. */
    private function isDelta(CatalogApp $app): bool
    {
        $artifact = $app->publishedArtifact;

        return (bool) config('storefront.signing.delta_builds.enabled')
            && $artifact !== null && SigningService::deltaSized($artifact->setRelation('app', $app));
    }

    private function enabled(Device $device, bool $negligible = false): bool
    {
        return config('storefront.signing.warmup_enabled', true)
            && $device->latestRegistration?->status === DeviceRegistrationStatus::Eligible
            && Certificate::query()->where('apple_team_id', $device->latestRegistration->apple_team_id)
                ->where('status', 'ACTIVE')->whereNotNull('runner_id')
                ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()->addDay()))
                ->exists()
            // Speculative builds must not be what fills the storage.
            && app(StorageJanitor::class)->allowsWarmup($device, $negligible);
    }
}
