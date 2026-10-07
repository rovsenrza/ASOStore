<?php

namespace App\Console\Commands;

use App\Enums\ArtifactStatus;
use App\Enums\PipelineJobStatus;
use App\Models\AppArtifact;
use App\Models\PipelineJob;
use App\Models\User;
use App\Services\Artifacts\ArtifactCleaningService;
use App\Services\Artifacts\ArtifactReviewService;
use App\Services\Artifacts\IpaCleaner;
use App\Services\Artifacts\LocalArtifactFile;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cleans a whole group of published listings (for example every game) without anyone
 * clicking through the admin panel: for each one it makes the cleaned copy with
 * tools/ipa-cleaner, waits for the normal inspection, approves and publishes it, which
 * supersedes the old build. The old artifact then expires and StorageJanitor removes its
 * file after the retention days. Every step goes through the same services as the panel.
 *
 * Safe to stop and run again: a listing whose published artifact is already a cleaned copy
 * without removable modules is skipped. A copy that fails inspection or review never
 * replaces the live build.
 */
class CleanCatalogBatch extends Command
{
    protected $signature = 'catalog:clean-batch
        {--category=* : Category slugs, e.g. games-arcade}
        {--artifact=* : Public IDs of published artifacts instead of a category}
        {--limit=0 : At most this many artifacts (0 = all)}
        {--plan : Only report what would be removed, change nothing}
        {--user=1 : Staff user ID that requests the cleanup and approves the copies}
        {--wait=1800 : Seconds to wait for one copy to be cleaned and inspected}
        {--no-notify : Do not message the Telegram admins at the end}';

    protected $description = 'Clean, inspect, approve and publish cleaned copies of many published IPAs in one unattended run';

    public function handle(ArtifactCleaningService $cleaning, ArtifactReviewService $review, IpaCleaner $cleaner, AuditService $audit): int
    {
        if (! $cleaner->enabled()) {
            $this->error('The IPA cleaner is not installed (storefront.ipa_cleaner).');

            return self::FAILURE;
        }
        $user = User::query()->find((int) $this->option('user'));
        if ($user === null) {
            $this->error('Unknown staff user.');

            return self::FAILURE;
        }
        $lock = fopen(storage_path('app/private/catalog-clean-batch.lock'), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Another catalog:clean-batch run is active.');

            return self::FAILURE;
        }

        $artifacts = $this->artifacts();
        $this->info($artifacts->count().' published artifact(s) to look at.');
        $plan = (bool) $this->option('plan');
        $log = storage_path('app/private/catalog-clean-'.now()->format('Ymd-His').($plan ? '-plan' : '').'.jsonl');
        $counts = [];

        foreach ($artifacts as $artifact) {
            $row = ['artifact' => $artifact->public_id, 'id' => $artifact->id, 'app' => $artifact->app?->name];
            try {
                $row += $this->handleOne($artifact, $user, $cleaning, $review, $cleaner, $audit, $plan);
            } catch (Throwable $exception) {
                report($exception);
                $row += ['result' => 'ERROR', 'detail' => mb_substr(get_class($exception).': '.$exception->getMessage(), 0, 400)];
            }
            $counts[$row['result']] = ($counts[$row['result']] ?? 0) + 1;
            file_put_contents($log, json_encode($row, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
            $this->line(sprintf('%-4d %-32s %-14s %s', $artifact->id, mb_substr((string) $row['app'], 0, 30), $row['result'], $row['detail'] ?? ''));
        }

        $this->info('Summary: '.json_encode($counts));
        $this->info('Log: '.$log);
        if (! $plan && ! $this->option('no-notify') && $artifacts->isNotEmpty()) {
            $this->notify($counts, $log);
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function handleOne(AppArtifact $artifact, User $user, ArtifactCleaningService $cleaning, ArtifactReviewService $review, IpaCleaner $cleaner, AuditService $audit, bool $plan): array
    {
        $analysis = $this->analysis($artifact, $cleaner, $audit, $plan);
        if ($analysis === null || isset($analysis['error'])) {
            return ['result' => 'NO_ANALYSIS', 'detail' => (string) ($analysis['error'] ?? 'analysis unavailable')];
        }
        $selection = self::select($analysis, config('storefront.catalog_clean'));
        if ($selection['remove'] === []) {
            return ['result' => 'NOTHING_TO_REMOVE', 'detail' => 'modules: '.implode(',', array_map('basename', array_column($analysis['modules'] ?? [], 'path')))];
        }
        $names = array_map('basename', $selection['remove']);
        if ($plan) {
            return ['result' => 'WOULD_CLEAN', 'detail' => 'remove: '.implode(',', $names)];
        }

        $reason = 'Catalog batch: remove '.implode(', ', $names);
        $job = $cleaning->request($artifact, $user, $selection, $reason, null);
        $job = $this->waitFor($job, (int) $this->option('wait'));
        if ($job->status !== PipelineJobStatus::Succeeded) {
            return ['result' => 'CLEAN_FAILED', 'detail' => $job->status->value.' '.$job->result_code.' '.mb_substr((string) ($job->error_class ?? ''), 0, 300)];
        }
        if (in_array($job->result_code, ['NOTHING_TO_CLEAN', 'ALREADY_EXISTS'], true)) {
            return ['result' => (string) $job->result_code, 'detail' => implode(',', $names)];
        }

        $copy = AppArtifact::query()->where('derived_from_artifact_id', $artifact->id)->orderByDesc('id')->first();
        if ($copy === null) {
            return ['result' => 'NO_COPY', 'detail' => 'cleaner reported '.$job->result_code];
        }
        $copy = $this->waitForInspection($copy, (int) $this->option('wait'));
        if ($copy->status !== ArtifactStatus::ProvenanceReview) {
            return ['result' => 'COPY_NOT_REVIEWABLE', 'detail' => $copy->status->value.' '.($copy->status_reason ?? ''), 'copy' => $copy->public_id];
        }

        $review->approve($copy, $user, 'Cleaned copy of a published listing (batch): '.implode(', ', $names), [
            'source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true,
        ], false);
        $copy->refresh();
        if ($copy->status !== ArtifactStatus::Ready) {
            return ['result' => 'NOT_READY', 'detail' => $copy->status->value.' '.($copy->status_reason ?? ''), 'copy' => $copy->public_id];
        }
        $review->publish($copy, $user);
        $audit->record('catalog.batch_cleaned', $copy, after: [
            'source_artifact_id' => $artifact->public_id,
            'removed' => $names,
        ], actor: Actor::user($user));

        return ['result' => 'PUBLISHED', 'detail' => implode(',', $names), 'copy' => $copy->public_id, 'copy_id' => $copy->id];
    }

    /**
     * The modules to remove: those whose file name matches `remove`, never a name in `keep`.
     * A bundled hook runtime (Substrate) goes only when nothing that could still use it stays.
     *
     * @param  array<string, mixed>  $analysis
     * @param  array{remove: list<string>, keep: list<string>, runtime: list<string>}  $rules
     * @return array{remove: list<string>, remove_extensions: list<string>, fix_metadata: bool}
     */
    public static function select(array $analysis, array $rules): array
    {
        $matches = fn (string $name, array $patterns) => array_filter($patterns, fn (string $pattern) => fnmatch(strtolower($pattern), strtolower($name))) !== [];
        $remove = [];
        $stays = false;
        foreach ($analysis['modules'] ?? [] as $module) {
            $name = basename((string) $module['path']);
            $isRuntime = $matches($name, $rules['runtime'] ?? []);
            if ($isRuntime) {
                continue;
            }
            if (! ($module['removable'] ?? false) || $matches($name, $rules['keep'] ?? []) || ! $matches($name, $rules['remove'] ?? [])) {
                $stays = true;

                continue;
            }
            $remove[] = (string) $module['path'];
        }
        if ($remove !== [] && ! $stays) {
            foreach ($analysis['modules'] ?? [] as $module) {
                if ($matches(basename((string) $module['path']), $rules['runtime'] ?? []) && ($module['removable'] ?? false)) {
                    $remove[] = (string) $module['path'];
                }
            }
        }

        return ['remove' => array_values(array_unique($remove)), 'remove_extensions' => [], 'fix_metadata' => true];
    }

    /** @return \Illuminate\Support\Collection<int, AppArtifact> */
    private function artifacts()
    {
        $limit = (int) $this->option('limit');
        $ids = array_map('strtolower', (array) $this->option('artifact'));

        return AppArtifact::query()->where('status', ArtifactStatus::Published->value)->whereNull('purged_at')
            ->when($ids !== [], fn ($query) => $query->whereIn('public_id', $ids))
            ->when($ids === [], fn ($query) => $query->whereHas('app', fn ($app) => $app->whereHas('category', fn ($category) => $category->whereIn('slug', (array) $this->option('category')))))
            ->with('app')->orderBy('size_bytes')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->get();
    }

    /** @return array<string, mixed>|null */
    private function analysis(AppArtifact $artifact, IpaCleaner $cleaner, AuditService $audit, bool $plan): ?array
    {
        $existing = $artifact->inspection['cleaning'] ?? null;
        if (is_array($existing) && ! isset($existing['error'])) {
            return $existing;
        }
        $file = LocalArtifactFile::open(Storage::disk($artifact->storage_disk), $artifact->storage_path);
        try {
            $analysis = $cleaner->analyze($file->path);
        } finally {
            $file->release();
        }
        if ($analysis !== null && ! $plan) {
            $artifact->forceFill(['inspection' => ['cleaning' => $analysis] + ($artifact->inspection ?? [])])->save();
            $audit->record('artifact.cleaning_analyzed', $artifact, after: ['modules' => count($analysis['modules'] ?? [])], actor: Actor::system('ipa-cleaner'));
        }

        return $analysis;
    }

    private function waitFor(PipelineJob $job, int $seconds): PipelineJob
    {
        $until = time() + $seconds;
        do {
            $job->refresh();
            if (in_array($job->status, [PipelineJobStatus::Succeeded, PipelineJobStatus::FailedPermanent, PipelineJobStatus::Cancelled], true)) {
                return $job;
            }
            sleep(5);
        } while (time() < $until);

        return $job;
    }

    private function waitForInspection(AppArtifact $copy, int $seconds): AppArtifact
    {
        $until = time() + $seconds;
        do {
            $copy->refresh();
            if (! in_array($copy->status, [ArtifactStatus::Uploaded, ArtifactStatus::Hashing, ArtifactStatus::Inspecting], true)) {
                return $copy;
            }
            sleep(5);
        } while (time() < $until);

        return $copy;
    }

    /** @param  array<string, int>  $counts */
    private function notify(array $counts, string $log): void
    {
        try {
            $lines = array_map(fn ($key, $value) => "• {$key}: {$value}", array_keys($counts), $counts);
            app(Messenger::class)->notifyAdmins(new Screen(
                "🧹 <b>Пакетная очистка каталога завершена</b>\n\n".implode("\n", $lines)."\n\nЖурнал на сервере: <code>".e($log).'</code>',
                [],
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
