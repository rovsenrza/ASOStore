<?php

namespace App\Console\Commands;

use App\Enums\ArtifactStatus;
use App\Models\AppArtifact;
use App\Models\User;
use App\Services\Artifacts\ArtifactReviewService;
use App\Services\Artifacts\CleanedCopyBuilder;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\Bot\Screen;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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

    public function handle(CleanedCopyBuilder $builder, ArtifactReviewService $review, AuditService $audit): int
    {
        if (! $builder->available()) {
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
                $row += $this->handleOne($artifact, $user, $builder, $review, $audit, $plan);
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
    private function handleOne(AppArtifact $artifact, User $user, CleanedCopyBuilder $builder, ArtifactReviewService $review, AuditService $audit, bool $plan): array
    {
        if ($plan) {
            $analysis = $builder->analysis($artifact, store: false);
            if ($analysis === null || isset($analysis['error'])) {
                return ['result' => 'NO_ANALYSIS', 'detail' => (string) ($analysis['error'] ?? 'analysis unavailable')];
            }
            $selection = CleanedCopyBuilder::select($analysis, config('storefront.catalog_clean'));
            if ($selection['remove'] === []) {
                return ['result' => 'NOTHING_TO_REMOVE', 'detail' => 'modules: '.implode(',', array_map('basename', array_column($analysis['modules'] ?? [], 'path')))];
            }

            return ['result' => 'WOULD_CLEAN', 'detail' => 'remove: '.implode(',', array_map('basename', $selection['remove']))];
        }

        $prepared = $builder->prepare($artifact, $user, 'Catalog batch', (int) $this->option('wait'));
        if ($prepared['result'] !== 'READY') {
            return ['result' => $prepared['result'], 'detail' => $prepared['detail']] + ($prepared['copy'] ? ['copy' => $prepared['copy']->public_id] : []);
        }
        $copy = $prepared['copy'];
        $review->publish($copy, $user);
        $audit->record('catalog.batch_cleaned', $copy, after: [
            'source_artifact_id' => $artifact->public_id,
            'removed' => $prepared['removed'],
        ], actor: Actor::user($user));

        return ['result' => 'PUBLISHED', 'detail' => $prepared['detail'], 'copy' => $copy->public_id, 'copy_id' => $copy->id];
    }

    /** @return Collection<int, AppArtifact> */
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
