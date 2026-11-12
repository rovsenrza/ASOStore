<?php

namespace App\Console\Commands;

use App\Enums\SignedBuildStatus;
use App\Models\AppArtifact;
use App\Services\Artifacts\ColdStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moves idle catalog originals off the local disk: an original nobody has installed (or had signed)
 * for --days, whose bucket copy exists with the same size, is deleted locally. ColdStorage brings it
 * back on the next install. `--status` shows the split, `--hydrate=<id>` brings one back by hand.
 */
class ArtifactsColdCommand extends Command
{
    protected $signature = 'artifacts:cold
        {--days= : idle days before an original goes cold (default config storefront.cold_storage.days)}
        {--dry-run : list what would move, delete nothing}
        {--status : local vs cold totals}
        {--hydrate= : artifact id to bring back now}';

    protected $description = 'Keep only recently used catalog originals on the local disk; the rest stay in the object bucket';

    public function handle(ColdStorage $cold): int
    {
        if ($cold->localDisk() === null) {
            $this->error('The artifacts disk is not local: nothing to do.');

            return self::FAILURE;
        }
        if ($id = $this->option('hydrate')) {
            $artifact = AppArtifact::query()->findOrFail((int) $id);
            $ok = $cold->ensureLocal($artifact);
            $this->line(($ok ? 'local: ' : 'FAILED: ').$artifact->storage_path);

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $artifacts = AppArtifact::query()->whereNull('purged_at')->where('storage_disk', 'artifacts')->get()->groupBy('storage_path');
        if ($this->option('status')) {
            $local = $artifacts->filter(fn ($rows) => $cold->isLocal($rows->first()));
            $sum = fn ($groups) => round($groups->sum(fn ($rows) => (int) $rows->first()->size_bytes) / 1024 ** 3, 1);
            $this->line("local: {$local->count()} files, {$sum($local)} GB; cold: ".($artifacts->count() - $local->count()).' files, '.$sum($artifacts->diff($local)).' GB');

            return self::SUCCESS;
        }
        if (! $cold->enabled()) {
            $this->error('Cold storage is off (ARTIFACTS_COLD_ENABLED).');

            return self::FAILURE;
        }

        $days = (int) ($this->option('days') ?: config('storefront.cold_storage.days', 14));
        $since = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');
        $ids = $artifacts->flatten()->pluck('id')->all();
        // Last time each artifact was asked for: an install, or any signing for it (warm-ups included).
        $lastInstall = DB::table('installations')->whereIn('artifact_id', $ids)->groupBy('artifact_id')->pluck(DB::raw('max(created_at)'), 'artifact_id');
        $lastBuild = DB::table('signed_builds')->whereIn('artifact_id', $ids)->groupBy('artifact_id')->pluck(DB::raw('max(created_at)'), 'artifact_id');
        $busy = DB::table('signed_builds')->whereIn('artifact_id', $ids)
            ->whereIn('status', [SignedBuildStatus::SigningPending->value, SignedBuildStatus::Signing->value, SignedBuildStatus::Signed->value])
            ->pluck('artifact_id')->flip();

        $moved = 0;
        $bytes = 0;
        foreach ($artifacts as $path => $rows) {
            $first = $rows->first();
            if (! $cold->isLocal($first)) {
                continue;
            }
            $recent = $rows->contains(fn (AppArtifact $row) => $row->created_at > $since
                || ($lastInstall[$row->id] ?? null) > $since->toDateTimeString()
                || ($lastBuild[$row->id] ?? null) > $since->toDateTimeString()
                || isset($busy[$row->id]));
            if ($recent) {
                continue;
            }
            if (! $cold->bucketHasCopy($first)) {
                $this->warn("no bucket copy, kept: {$path}");

                continue;
            }
            $size = (int) $first->size_bytes;
            $this->line(($dry ? 'would move ' : 'moved ').$path.' ('.round($size / 1024 ** 2).' MB, artifact '.$rows->pluck('id')->implode(',').')');
            if (! $dry) {
                @unlink($cold->localPath($first));
                Log::info('artifact.cold', ['path' => $path, 'bytes' => $size]);
            }
            $moved++;
            $bytes += $size;
        }
        $this->info(($dry ? 'Would move ' : 'Moved ')."{$moved} originals, ".round($bytes / 1024 ** 3, 1)." GB (idle {$days}+ days).");

        return self::SUCCESS;
    }
}
