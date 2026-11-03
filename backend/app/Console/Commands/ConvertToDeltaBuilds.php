<?php

namespace App\Console\Commands;

use App\Enums\DeviceRegistrationStatus;
use App\Enums\SignedBuildStatus;
use App\Exceptions\ApiException;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\SignedBuild;
use App\Services\Artifacts\StorageJanitor;
use App\Services\Installations\InstallationService;
use App\Services\Signing\SigningService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Switches catalog apps over to delta builds (docs/adr/DELTA_BUILDS.md), least installed first: the app is
 * marked, each team's whole build of its current version is ended and its file deleted, and a delta build is
 * started for that team in its place (the runner takes it when idle). A team that installs the app in the
 * minute in between waits for that signing, so the quiet apps go first.
 */
class ConvertToDeltaBuilds extends Command
{
    protected $signature = 'signing:convert-delta
        {--least-popular=0 : Convert this many apps, the least installed over the last 30 days first}
        {--app=* : Convert these app ids (whatever their popularity)}
        {--dry-run : Only list the apps and the whole builds that would be replaced}';

    protected $description = 'Replace catalog apps\' whole team builds with delta builds, least popular first';

    public function handle(StorageJanitor $janitor, InstallationService $installations): int
    {
        if (! config('storefront.signing.delta_builds.enabled')) {
            $this->error('Delta builds are switched off (STOREFRONT_SIGNING_DELTA_BUILDS).');

            return self::FAILURE;
        }
        $apps = $this->apps();
        if ($apps->isEmpty()) {
            $this->info('No app to convert.');

            return self::SUCCESS;
        }

        $devices = self::teamDevices();
        $rows = [];
        $totals = ['retired' => 0, 'started' => 0, 'bytes' => 0];
        foreach ($apps as $app) {
            $artifact = $app->publishedArtifact;
            $builds = $this->wholeBuilds($app);
            $retired = $started = $bytes = 0;
            $busy = [];
            if (! $this->option('dry-run')) {
                if ($app->delta_builds_at === null) {
                    $app->forceFill(['delta_builds_at' => now()])->save();
                }
                foreach ($builds as $build) {
                    if (! $janitor->retire($build, 'DELTA_CONVERSION')) {
                        // Installed in the last minutes: the next run replaces it.
                        $busy[] = $build->public_id;

                        continue;
                    }
                    $retired++;
                    $bytes += (int) $build->size_bytes;
                    $device = $devices[$build->apple_team_id] ?? null;
                    if ($device === null) {
                        continue;
                    }
                    try {
                        $started += $installations->prewarm($device, $app, sharedOnly: true)->wasRecentlyCreated ? 1 : 0;
                    } catch (ApiException) {
                        // Not installable on that team's newest device: the next install there builds it.
                    }
                }
            }
            $totals['retired'] += $retired;
            $totals['started'] += $started;
            $totals['bytes'] += $bytes;
            $rows[] = [
                $app->id, mb_strimwidth((string) $app->name, 0, 30, '…'), (int) $app->recent_installations_count,
                $builds->count(), self::gb((int) $builds->sum('size_bytes')), $retired, $started, $busy === [] ? '' : count($busy).' in use',
            ];
        }

        $this->table(['app', 'name', 'installs 30d', 'whole builds', 'their size', 'ended', 'delta started', 'skipped'], $rows);
        $this->line(sprintf('%s%d apps, %d whole builds ended (%s freed), %d delta builds queued.',
            $this->option('dry-run') ? 'Dry run, nothing changed. ' : '',
            $apps->count(), $totals['retired'], self::gb($totals['bytes']), $totals['started']));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, CatalogApp>
     */
    private function apps(): Collection
    {
        $ids = array_map('intval', (array) $this->option('app'));
        $query = CatalogApp::query()->visibleToCustomers()->where('is_storefront', false)
            ->whereHas('publishedArtifact', fn (Builder $query) => $query->where('size_bytes', '<', SigningService::DELTA_MAX_BASE_BYTES))
            ->with('publishedArtifact')
            ->withCount(['installations as recent_installations_count' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(30))]);
        if ($ids !== []) {
            return $query->whereIn('id', $ids)->orderBy('id')->get();
        }

        // Only apps that still hold whole team builds (also one already marked whose build was in use on an
        // earlier run): those are what there is to gain. One whose pack failed verification once is signed
        // whole for good (SigningService::deltaAllowed).
        return $query->get()
            ->filter(fn (CatalogApp $app) => ! Cache::has(SigningService::deltaOffKey($app->publishedArtifact))
                && $this->wholeBuilds($app)->isNotEmpty())
            ->sortBy(fn (CatalogApp $app) => [(int) $app->recent_installations_count, $app->id])
            ->take(max(0, (int) $this->option('least-popular')))
            ->values();
    }

    /**
     * @return Collection<int, SignedBuild>
     */
    private function wholeBuilds(CatalogApp $app): Collection
    {
        return SignedBuild::query()->where('artifact_id', $app->publishedArtifact->id)
            // Made after the switch: the runner fell back to a whole build (pack not worth it); keep it.
            ->when($app->delta_builds_at !== null, fn ($query) => $query->where('created_at', '<=', $app->delta_builds_at))
            ->where('status', SignedBuildStatus::Deliverable->value)
            ->where('format', SignedBuild::FORMAT_FULL)
            ->whereNull('device_id')->whereNull('purged_at')->whereNotNull('storage_path')
            ->get();
    }

    /**
     * Each team's newest eligible device: the one a new team build is made for.
     *
     * @return array<int, Device>
     */
    public static function teamDevices(): array
    {
        return DeviceRegistration::query()
            ->where('status', DeviceRegistrationStatus::Eligible->value)
            ->whereNotNull('apple_device_id')
            ->whereIn('id', DeviceRegistration::query()->selectRaw('MAX(id)')->groupBy('device_id'))
            ->orderByDesc('eligible_at')->orderByDesc('id')
            ->with('device.latestRegistration')
            ->get()
            ->unique('apple_team_id')
            ->filter(fn (DeviceRegistration $registration) => $registration->device !== null)
            ->mapWithKeys(fn (DeviceRegistration $registration) => [(int) $registration->apple_team_id => $registration->device])
            ->all();
    }

    private static function gb(int $bytes): string
    {
        return number_format($bytes / 1024 ** 3, 2, '.', ' ').' GB';
    }
}
