<?php

namespace App\Services\Artifacts;

use App\Enums\ArtifactStatus;
use App\Enums\InstallationStatus;
use App\Enums\SignedBuildStatus;
use App\Models\AppArtifact;
use App\Models\Device;
use App\Models\SignedBuild;
use App\Models\UploadSession;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Installations\InstallationService;
use App\StateMachines\StateMachine;
use Carbon\CarbonInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use Throwable;

/**
 * Keeps storage from piling up as devices × apps grow (scheduled every ten minutes).
 *
 * A signed build is a per-device delivery copy that can always be signed again from its
 * original, so idle ones are removed: prepared ahead and never asked for, already
 * installed, or ready but never tapped. Above the budget the least recently used go
 * first. A build used within the last half hour is never touched, so a running download
 * keeps its file. Files of builds that can no longer be installed, superseded originals,
 * objects nothing refers to, abandoned multipart uploads and temporary copies left by
 * killed workers go as well. Database rows stay: they are audit evidence.
 */
final class StorageJanitor
{
    public const LAST_RUN = 'storage-janitor:last-run';

    private const SWEPT = 'storage-janitor:objects-swept';

    private const TERMINAL_BUILDS = [SignedBuildStatus::Expired, SignedBuildStatus::Revoked, SignedBuildStatus::SigningFailed, SignedBuildStatus::ValidationFailed];

    /** Installations still heading for a download of their build. */
    private const WAITING = [InstallationStatus::Preparing, InstallationStatus::ReadyToInstall, InstallationStatus::Authorized, InstallationStatus::ManifestFetched];

    /** Reclaim order above the budget: nobody asked for it, already installed, then waiting for the tap. */
    private const KIND_ORDER = ['warm' => 0, 'delivered' => 1, 'ready' => 2];

    /** @var array<string, int> */
    private array $summary = [];

    /** @var array<int, true> Builds already handled in this run (a dry run changes nothing to re-read). */
    private array $reclaimed = [];

    private bool $dryRun = false;

    public function __construct(
        private readonly StateMachine $states,
        private readonly InstallationService $installations,
        private readonly ArtifactFileCache $cache,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array<string, int> What was (or, in a dry run, would be) removed.
     */
    public function run(bool $dryRun = false, bool $sweepObjects = false): array
    {
        $this->dryRun = $dryRun;
        $this->reclaimed = [];
        $this->summary = array_fill_keys([
            'idle_builds', 'budget_builds', 'builds_purged', 'build_bytes', 'originals_purged', 'original_bytes',
            'orphan_objects', 'orphan_bytes', 'multipart_aborted', 'temp_files', 'cache_files', 'errors',
        ], 0);

        $this->expireIdleBuilds();
        $this->enforceBudget();
        $this->purgeTerminalBuilds();
        $this->purgeSupersededOriginals();
        // Listing the bucket is the expensive part: every six hours, or when asked.
        if ($sweepObjects || $dryRun || Cache::add(self::SWEPT, true, now()->addHours(6))) {
            $this->sweepObjects();
        }
        $this->sweepLocal();

        if (! $dryRun) {
            Cache::forever(self::LAST_RUN, ['at' => now()->toIso8601ZuluString(), 'summary' => $this->summary]);
            $removed = array_sum(array_diff_key($this->summary, ['errors' => 0, 'build_bytes' => 0, 'original_bytes' => 0, 'orphan_bytes' => 0]));
            if ($removed > 0 || $this->summary['errors'] > 0) {
                $this->audit->record('storage.janitor_applied', after: $this->summary, actor: Actor::system('storage'));
            }
        }

        return $this->summary;
    }

    /**
     * Whether another speculative build may start: there is room in the budget, the
     * device does not already hold many unused ones, and the local disk is not filling.
     */
    public function allowsWarmup(Device $device): bool
    {
        $settings = config('storefront.build_storage');
        if ($this->signedBytes() >= $settings['budget_bytes'] * $settings['warmup_budget_ratio']) {
            return false;
        }
        if (! ArtifactFileCache::diskHasRoom(storage_path('app'), 0)) {
            return false;
        }
        $unused = SignedBuild::query()
            ->where('device_id', $device->id)
            ->whereIn('status', [SignedBuildStatus::SigningPending->value, SignedBuildStatus::Signing->value, SignedBuildStatus::Signed->value,
                SignedBuildStatus::SignatureVerified->value, SignedBuildStatus::Deliverable->value])
            ->whereNull('purged_at')
            ->whereDoesntHave('installations')
            ->count();

        return $unused < $settings['warm_builds_per_device'];
    }

    /** Bytes held by signed builds whose file still exists. */
    public function signedBytes(): int
    {
        return (int) SignedBuild::query()->whereNull('purged_at')->whereNotNull('storage_path')->sum('size_bytes');
    }

    /**
     * For the admin dashboard.
     *
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $kinds = ['warm' => ['count' => 0, 'bytes' => 0], 'ready' => ['count' => 0, 'bytes' => 0], 'delivered' => ['count' => 0, 'bytes' => 0]];
        foreach ($this->deliverable() as $build) {
            $kinds[$this->kind($build)]['count']++;
            $kinds[$this->kind($build)]['bytes'] += (int) $build->size_bytes;
        }
        $originals = AppArtifact::query()->whereNull('purged_at');
        $root = storage_path('app');

        return [
            'signed_builds' => [
                'bytes' => $this->signedBytes(),
                'budget_bytes' => (int) config('storefront.build_storage.budget_bytes'),
                'by_kind' => $kinds,
            ],
            'originals' => ['count' => (clone $originals)->count(), 'bytes' => (int) (clone $originals)->sum('size_bytes')],
            'disk' => ['free_bytes' => (int) (@disk_free_space($root) ?: 0), 'total_bytes' => (int) (@disk_total_space($root) ?: 0)],
            'cache' => $this->cache->usage(),
            'policy' => [
                'warm_idle_hours' => (int) config('storefront.build_storage.warm_idle_hours'),
                'ready_idle_hours' => (int) config('storefront.build_storage.ready_idle_hours'),
                'delivered_idle_hours' => (int) config('storefront.build_storage.delivered_idle_hours'),
            ],
            'last_run' => Cache::get(self::LAST_RUN),
        ];
    }

    private function expireIdleBuilds(): void
    {
        $hours = [
            'warm' => (int) config('storefront.build_storage.warm_idle_hours'),
            'delivered' => (int) config('storefront.build_storage.delivered_idle_hours'),
            'ready' => (int) config('storefront.build_storage.ready_idle_hours'),
        ];
        foreach ($this->deliverable() as $build) {
            if ($this->lastUsed($build)->lt(now()->subHours($hours[$this->kind($build)])) && $this->reclaim($build, 'IDLE')) {
                $this->summary['idle_builds']++;
            }
        }
    }

    private function enforceBudget(): void
    {
        $budget = (int) config('storefront.build_storage.budget_bytes');
        // A dry run removed nothing yet: count what the idle pass would have freed.
        $used = $this->signedBytes() - ($this->dryRun ? $this->summary['build_bytes'] : 0);
        if ($used <= $budget) {
            return;
        }
        // Down to 90 %, so the next build does not trigger another round at once.
        $target = (int) ($budget * 0.9);
        $candidates = $this->deliverable()
            ->sortBy(fn (SignedBuild $build) => [self::KIND_ORDER[$this->kind($build)], $this->lastUsed($build)->getTimestamp()]);
        foreach ($candidates as $build) {
            if ($used <= $target) {
                break;
            }
            if ($this->reclaim($build, 'STORAGE_BUDGET')) {
                $used -= (int) $build->size_bytes;
                $this->summary['budget_builds']++;
            }
        }
    }

    /** Builds that can no longer be installed lose their file after a short grace period. */
    private function purgeTerminalBuilds(): void
    {
        $builds = SignedBuild::query()
            ->whereIn('status', array_map(fn (SignedBuildStatus $status) => $status->value, self::TERMINAL_BUILDS))
            ->whereNotNull('storage_path')
            ->whereNull('purged_at')
            ->where('updated_at', '<', now()->subMinutes(15))
            ->get();
        foreach ($builds as $build) {
            $this->deleteBuildFile($build);
        }
    }

    private function purgeSupersededOriginals(): void
    {
        $rules = [
            ArtifactStatus::Expired->value => (int) config('storefront.build_storage.superseded_original_days'),
            ArtifactStatus::Revoked->value => (int) config('storefront.retention.rejected_artifact_days'),
        ];
        foreach ($rules as $status => $days) {
            $artifacts = AppArtifact::query()->where('status', $status)->whereNull('purged_at')
                ->where('updated_at', '<', now()->subDays($days))->get();
            foreach ($artifacts as $artifact) {
                // The same file uploaded again for a live artifact shares this path.
                $shared = AppArtifact::query()->whereKeyNot($artifact->id)->where('storage_path', $artifact->storage_path)->whereNull('purged_at')->exists();
                $live = SignedBuild::query()->where('artifact_id', $artifact->id)->whereNull('purged_at')->whereNotNull('storage_path')
                    ->whereNotIn('status', array_map(fn (SignedBuildStatus $status) => $status->value, self::TERMINAL_BUILDS))->exists();
                if ($live) {
                    continue;
                }
                $this->summary['originals_purged']++;
                $this->summary['original_bytes'] += (int) $artifact->size_bytes;
                if ($this->dryRun) {
                    continue;
                }
                try {
                    if (! $shared) {
                        Storage::disk($artifact->storage_disk)->delete($artifact->storage_path);
                    }
                    $artifact->forceFill(['purged_at' => now()])->save();
                } catch (Throwable $exception) {
                    $this->failed('original', $artifact->public_id, $exception);
                }
            }
        }
    }

    /**
     * Objects no row accounts for: a crashed upload, a deletion that failed half-way,
     * abandoned multipart parts. Unknown prefixes are never touched.
     */
    private function sweepObjects(): void
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk('artifacts');
        try {
            foreach (['signed', 'originals', 'uploads'] as $prefix) {
                foreach ($disk->listContents($prefix, true) as $object) {
                    if (! $object instanceof FileAttributes || ! $this->orphaned($object->path(), Carbon::createFromTimestamp((int) $object->lastModified()))) {
                        continue;
                    }
                    $this->summary['orphan_objects']++;
                    $this->summary['orphan_bytes'] += (int) $object->fileSize();
                    if (! $this->dryRun) {
                        $disk->delete($object->path());
                    }
                }
            }
            if ($disk instanceof AwsS3V3Adapter) {
                $this->abortStaleMultipart($disk);
            }
        } catch (Throwable $exception) {
            $this->failed('objects', 'artifacts', $exception);
        }
    }

    private function orphaned(string $path, Carbon $modified): bool
    {
        if (preg_match('~^signed/([0-9a-z]{26})\.ipa$~D', $path, $match) === 1) {
            if ($modified->gt(now()->subHour())) {
                return false;
            }
            $build = SignedBuild::query()->where('public_id', $match[1])->first();

            // Terminal builds that still name their file are purgeTerminalBuilds' to remove.
            return $build === null || $build->purged_at !== null
                || ($build->storage_path === null && in_array($build->status, self::TERMINAL_BUILDS, true));
        }
        if (preg_match('~^originals/[a-f0-9]{2}/[a-f0-9]{64}\.ipa$~D', $path) === 1) {
            return $modified->lt(now()->subDay())
                && AppArtifact::query()->where('storage_path', $path)->whereNull('purged_at')->doesntExist();
        }
        if (preg_match('~^uploads/([0-9a-z]{26})/~', $path, $match) === 1) {
            return $modified->lt(now()->subDays(2))
                && UploadSession::query()->where('public_id', $match[1])->whereIn('status', ['OPEN', 'ASSEMBLING'])->doesntExist();
        }

        return false;
    }

    /** No upload of ours takes a day; older unfinished multipart uploads only hold parts. */
    private function abortStaleMultipart(AwsS3V3Adapter $disk): void
    {
        $client = $disk->getClient();
        $bucket = $disk->getConfig()['bucket'];
        $options = ['Bucket' => $bucket];
        do {
            $page = $client->listMultipartUploads($options);
            foreach ($page['Uploads'] ?? [] as $upload) {
                if ($upload['Initiated']->getTimestamp() >= now()->subDay()->getTimestamp()) {
                    continue;
                }
                $this->summary['multipart_aborted']++;
                if (! $this->dryRun) {
                    $client->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $upload['Key'], 'UploadId' => $upload['UploadId']]);
                }
            }
            $options['KeyMarker'] = $page['NextKeyMarker'] ?? '';
            $options['UploadIdMarker'] = $page['NextUploadIdMarker'] ?? '';
        } while ($page['IsTruncated'] ?? false);
    }

    /** Temporary IPA copies of killed workers, and cached copies of files that are gone. */
    private function sweepLocal(): void
    {
        $directory = (string) config('storefront.build_storage.temp_path');
        $stale = now()->subHours((int) config('storefront.build_storage.temp_stale_hours'))->getTimestamp();
        foreach (glob($directory.'/*') ?: [] as $file) {
            if (is_file($file) && ! is_link($file) && filemtime($file) < $stale) {
                $this->summary['temp_files']++;
                if (! $this->dryRun) {
                    @unlink($file);
                }
            }
        }
        if (! $this->dryRun) {
            $live = SignedBuild::query()->whereNull('purged_at')->whereNotNull('sha256')
                ->whereIn('status', [SignedBuildStatus::Signed->value, SignedBuildStatus::SignatureVerified->value, SignedBuildStatus::Deliverable->value])
                ->pluck('sha256')->flip();
            $this->summary['cache_files'] += $this->cache->prune(fn (string $sha256) => $live->has($sha256))['files'];
        }
    }

    /**
     * Expires one idle build, ends the installations waiting for it, and deletes its file.
     */
    private function reclaim(SignedBuild $build, string $reason): bool
    {
        if (isset($this->reclaimed[$build->id]) || $this->inUse($build)) {
            return false;
        }
        $this->reclaimed[$build->id] = true;
        if ($this->dryRun) {
            $this->deleteBuildFile($build);

            return true;
        }

        try {
            $reclaimed = DB::transaction(function () use ($build, $reason) {
                $locked = SignedBuild::query()->whereKey($build->id)->lockForUpdate()->first();
                if ($locked === null || $locked->status !== SignedBuildStatus::Deliverable || $locked->purged_at !== null || $this->inUse($locked)) {
                    return false;
                }
                $this->states->transition($locked, SignedBuildStatus::Expired, 'Idle signed build reclaimed.', Actor::system('storage'), extra: ['status_reason' => $reason]);
                $this->installations->buildReclaimed($locked, 'BUILD_EXPIRED');

                return true;
            });
        } catch (Throwable $exception) {
            $this->failed('build', $build->public_id, $exception);

            return false;
        }
        if ($reclaimed) {
            $this->deleteBuildFile($build->refresh());
        }

        return $reclaimed;
    }

    private function deleteBuildFile(SignedBuild $build): void
    {
        if (! $this->dryRun) {
            try {
                // signed/<public_id>.ipa belongs to this build alone.
                Storage::disk('artifacts')->delete((string) $build->storage_path);
                if ($build->sha256 !== null) {
                    $this->cache->forget($build->sha256);
                }
                $build->forceFill(['purged_at' => now()])->saveQuietly();
            } catch (Throwable $exception) {
                $this->failed('build', $build->public_id, $exception);

                return;
            }
        }
        $this->summary['builds_purged']++;
        $this->summary['build_bytes'] += (int) $build->size_bytes;
    }

    /**
     * @return Collection<int, SignedBuild>
     */
    private function deliverable(): Collection
    {
        return SignedBuild::query()
            ->where('status', SignedBuildStatus::Deliverable->value)
            ->whereNull('purged_at')
            ->whereNotNull('storage_path')
            ->with('installations:id,signed_build_id,status')
            ->get();
    }

    /** @return 'warm'|'ready'|'delivered' */
    private function kind(SignedBuild $build): string
    {
        if ($build->installations->isEmpty()) {
            return 'warm';
        }

        return $build->installations->contains(fn ($installation) => in_array($installation->status, self::WAITING, true)) ? 'ready' : 'delivered';
    }

    private function lastUsed(SignedBuild $build): CarbonInterface
    {
        $times = array_filter([$build->last_used_at, $build->updated_at, $build->created_at]);

        return $times === [] ? now() : max($times);
    }

    private function inUse(SignedBuild $build): bool
    {
        return $this->lastUsed($build)->gt(now()->subMinutes((int) config('storefront.build_storage.in_use_minutes', 30)));
    }

    private function failed(string $kind, string $id, Throwable $exception): void
    {
        $this->summary['errors']++;
        Log::warning('storage.janitor_failed', ['kind' => $kind, 'id' => $id, 'error' => $exception->getMessage()]);
    }
}
