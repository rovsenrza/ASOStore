<?php

namespace App\Console\Commands;

use App\Enums\SignedBuildStatus;
use App\Exceptions\ApiException;
use App\Models\CatalogApp;
use App\Models\Certificate;
use App\Models\SignedBuild;
use App\Services\Installations\InstallationService;
use App\Services\Signing\SigningService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Delta v3 migration (docs/adr/DELTA_V3.md, "Migration"): queues a speculative `delta-v2` team build of every published
 * catalog app (least installed first) for every active team's newest eligible device. Nothing is ended here: once a
 * team's v2 build is deliverable, the storage janitor ends that team's older whole and `delta-v1` builds of the
 * artifact that serve no device the new one does not.
 */
class MigrateToDeltaV2Builds extends Command
{
    protected $signature = 'signing:migrate-delta-v2
        {--apps=all : "all", or this many apps, the least installed over the last 30 days first}
        {--limit= : Queue at most this many builds in this run}
        {--dry-run : Only list what would be queued}';

    protected $description = 'Queue delta v3 (delta-v2) team builds of the catalog, least popular apps first';

    private const LIVE = [
        SignedBuildStatus::SigningPending, SignedBuildStatus::Signing, SignedBuildStatus::Signed,
        SignedBuildStatus::SignatureVerified, SignedBuildStatus::Deliverable,
    ];

    public function handle(InstallationService $installations): int
    {
        if (! SigningService::deltaV2()) {
            $this->error('Delta v3 is switched off (STOREFRONT_SIGNING_DELTA_BUILDS and STOREFRONT_SIGNING_DELTA_V2).');

            return self::FAILURE;
        }
        $appsOption = (string) $this->option('apps');
        if ($appsOption !== 'all' && ! ctype_digit($appsOption)) {
            $this->error('--apps takes "all" or a number.');

            return self::FAILURE;
        }
        $limit = $this->option('limit') === null ? PHP_INT_MAX : max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $apps = $this->apps($appsOption === 'all' ? null : (int) $appsOption);
        // Only teams a runner can sign for right now.
        $signable = Certificate::query()->where('status', 'ACTIVE')->whereNotNull('runner_id')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()->addDay()))
            ->pluck('apple_team_id')->map(fn ($id) => (int) $id)->flip();
        $devices = array_filter(ConvertToDeltaBuilds::teamDevices(), fn (int $team) => $signable->has($team), ARRAY_FILTER_USE_KEY);

        $rows = [];
        $totals = ['done' => 0, 'queued' => 0, 'waiting' => 0, 'skipped' => 0];
        foreach ($apps as $app) {
            $artifact = $app->publishedArtifact;
            $haveV2 = SignedBuild::query()->where('artifact_id', $artifact->id)->whereNull('device_id')
                ->where('format', SigningService::FORMAT_DELTA_V2)->whereNull('purged_at')
                ->whereIn('status', array_map(fn (SignedBuildStatus $status) => $status->value, self::LIVE))
                ->pluck('apple_team_id')->map(fn ($id) => (int) $id)->flip();
            $count = ['done' => 0, 'queued' => 0, 'waiting' => 0, 'skipped' => 0];
            foreach ($devices as $team => $device) {
                if ($haveV2->has($team)) {
                    $count['done']++;

                    continue;
                }
                if ($dryRun || $limit <= $totals['queued'] + $count['queued']) {
                    $count['waiting']++;

                    continue;
                }
                try {
                    $build = $installations->prewarm($device, $app, sharedOnly: true, deltaV2Only: true);
                    $count[$build->wasRecentlyCreated ? 'queued' : 'waiting']++;
                } catch (ApiException) {
                    // Not installable on that team's newest device (iOS version, family).
                    $count['skipped']++;
                }
            }
            foreach ($count as $key => $value) {
                $totals[$key] += $value;
            }
            $rows[] = [$app->id, mb_strimwidth((string) $app->name, 0, 30, '…'), (int) $app->recent_installations_count,
                self::mb((int) $artifact->size_bytes), $count['done'], $count['queued'], $count['waiting'], $count['skipped']];
        }

        $this->table(['app', 'name', 'installs 30d', 'size', 'v2 live', 'queued', $dryRun ? 'to queue' : 'waiting', 'not installable'], $rows);
        $this->line(sprintf('%s%d apps × %d teams: %d already delta-v2, %d builds queued, %d %s, %d not installable.',
            $dryRun ? 'Dry run, nothing queued. ' : '', $apps->count(), count($devices),
            $totals['done'], $totals['queued'], $totals['waiting'], $dryRun ? 'to queue' : 'waiting (in progress or over --limit)', $totals['skipped']));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, CatalogApp>
     */
    private function apps(?int $count): Collection
    {
        $apps = CatalogApp::query()->visibleToCustomers()->where('is_storefront', false)
            ->whereHas('publishedArtifact', fn (Builder $query) => $query->where('size_bytes', '>', 0)->where('size_bytes', '<', SigningService::DELTA_MAX_BASE_BYTES))
            ->with('publishedArtifact')
            ->withCount(['installations as recent_installations_count' => fn (Builder $query) => $query->where('created_at', '>=', now()->subDays(30))])
            ->get()
            // One whose pack failed verification once is signed whole for good (SigningService::deltaAllowed).
            ->filter(fn (CatalogApp $app) => ! Cache::has(SigningService::deltaOffKey($app->publishedArtifact)))
            ->sortBy(fn (CatalogApp $app) => [(int) $app->recent_installations_count, $app->id])
            ->values();

        return $count === null ? $apps : $apps->take($count)->values();
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1024 ** 2, 1, '.', ' ').' MB';
    }
}
