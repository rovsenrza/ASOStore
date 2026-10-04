<?php

namespace App\Services\Artifacts;

use App\Enums\AppVisibility;
use App\Enums\ArtifactStatus;
use App\Enums\ErrorCode;
use App\Enums\PipelineJobStatus;
use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Exceptions\IllegalStateTransition;
use App\Jobs\QuickPublishJob;
use App\Models\AppArtifact;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Models\PipelineJob;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\CatalogImageService;
use App\Services\Catalog\IpaIconExtractor;
use App\Services\Catalog\StoreMetadataLookup;
use App\Services\Catalog\TeamEligibilityGranter;
use App\Services\Pipeline\PipelineJobService;
use App\Services\Pipeline\RetryLater;
use App\StateMachines\StateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-step publishing from the admin: an uploaded IPA is inspected, cleaned of injected libraries,
 * matched to a catalog listing by its bundle ID (or turned into a new listing), approved and
 * published, the way an operator would do it by hand across the artifacts and apps pages.
 *
 * It uses the ordinary services (inspection, cleaning, review, publish), so every state change is
 * audited and the same rules apply: an unclean malware scan, a missing team approval or a
 * four-eyes review requirement stops it and leaves the file waiting for a person. run() resumes
 * from whatever the database says, so a retry never repeats work.
 */
class QuickPublishService
{
    public const STAGE_PUBLISHED = 'PUBLISHED';

    /** Needs a person; the message says why. A retry with different options can continue. */
    public const STAGE_HELD = 'HELD';

    public const STAGE_REJECTED = 'REJECTED';

    public const STAGE_DUPLICATE = 'DUPLICATE';

    public const STAGE_FAILED = 'FAILED';

    public const TERMINAL = [self::STAGE_PUBLISHED, self::STAGE_HELD, self::STAGE_REJECTED, self::STAGE_DUPLICATE, self::STAGE_FAILED];

    public const DEFAULT_OPTIONS = ['remove_unknown_libraries' => true, 'allow_downgrade' => false, 'allow_other_sources' => false];

    private const STAGING_PREFIX = 'quick-';

    private const DISCARDED = [ArtifactStatus::Rejected->value, ArtifactStatus::InspectionFailed->value, ArtifactStatus::ProvenanceFailed->value];

    /** Cleaner categories that are not part of the app: promotions, hooks and unreferenced libraries. */
    private const UNWANTED = ['promotion', 'suspected-promotion', 'suspected-hook', 'hook-runtime', 'unreferenced-library'];

    private const CHECKLIST = ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true];

    public function __construct(
        private readonly ArtifactCleaningService $cleaning,
        private readonly ArtifactReviewService $review,
        private readonly ChunkedUploadService $uploads,
        private readonly TeamEligibilityGranter $eligibility,
        private readonly CatalogImageService $images,
        private readonly StoreMetadataLookup $lookup,
        private readonly IpaIconExtractor $icons,
        private readonly PipelineJobService $jobs,
        private readonly AuditService $audit,
        private readonly StateMachine $states,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('storefront.quick_publish.enabled', true);
    }

    /* ---------- Upload ---------- */

    /**
     * Opens a chunked upload for one IPA. The file waits in a draft staging listing until its
     * bundle ID says where it belongs.
     */
    public function start(User $user, string $filename, int $sizeBytes, ?string $sha256, ?string $ip): UploadSession
    {
        if (! self::enabled()) {
            throw new ApiException(ErrorCode::ServiceUnavailable, 'Быстрая публикация отключена.');
        }
        if (preg_match('/\.ipa$/i', $filename) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['filename' => 'Нужен файл .ipa.']);
        }
        if ($sizeBytes < 1 || $sizeBytes > (int) config('storefront.quick_publish.max_bytes')) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['size_bytes' => 'Файл слишком большой.']);
        }

        return DB::transaction(function () use ($user, $filename, $sizeBytes, $sha256, $ip) {
            $key = strtolower((string) Str::ulid());
            $staging = CatalogApp::create([
                'slug' => self::STAGING_PREFIX.$key,
                'name' => $this->nameFromFilename($filename),
                'bundle_identifier' => config('storefront.artifacts.own_bundle_prefix').'quick'.substr($key, -16),
                'category_id' => $this->category('imported')->id,
                'publisher_id' => $this->publisher('Разработчик не указан')->id,
                'source_type' => SourceType::CustomerProvided,
                'visibility' => AppVisibility::Draft,
            ]);
            $chunkSize = UploadSession::CHUNK_SIZE;
            $upload = UploadSession::create([
                'app_id' => $staging->id,
                'uploaded_by' => $user->id,
                'original_filename' => basename($filename),
                'expected_size' => $sizeBytes,
                'expected_sha256' => $sha256 !== null ? strtolower($sha256) : null,
                'chunk_size' => $chunkSize,
                'chunk_count' => (int) ceil($sizeBytes / $chunkSize),
                'source_type' => SourceType::CustomerProvided->value,
                'declaration_version' => (string) config('storefront.quick_publish.declaration_version'),
                'declaration_accepted_at' => now(),
                'declaration_ip' => $ip,
                'status' => 'OPEN',
                'expires_at' => now()->addDay(),
            ]);
            $this->audit->record('quick_publish.upload_started', $staging, after: [
                'filename' => $upload->original_filename,
                'size_bytes' => $sizeBytes,
            ], actor: Actor::user($user));

            return $upload;
        });
    }

    /**
     * Assembles the uploaded file and starts the pipeline that takes it to the catalog.
     *
     * @param  array<string, mixed>  $options
     */
    public function complete(UploadSession $upload, User $user, array $options, ?string $ip): PipelineJob
    {
        $staging = CatalogApp::withTrashed()->find($upload->app_id);
        if ($staging === null || ! str_starts_with($staging->slug, self::STAGING_PREFIX) || $upload->uploaded_by !== $user->id) {
            throw new ApiException(ErrorCode::NotFound);
        }
        $artifact = $this->uploads->complete($upload)->refresh();

        $job = $this->jobs->create(QuickPublishJob::TYPE, QuickPublishJob::idempotencyKey($artifact), $artifact, [
            'artifact_id' => $artifact->public_id,
            'filename' => $upload->original_filename,
            'size_bytes' => $artifact->size_bytes,
            'requested_by' => $user->id,
            'ip' => $ip,
            'options' => array_replace(self::DEFAULT_OPTIONS, array_intersect_key($options, self::DEFAULT_OPTIONS)),
            'stage' => 'INSPECTING',
            'message' => 'Файл загружен, идёт проверка.',
            'steps' => [['at' => now()->toIso8601ZuluString(), 'stage' => 'UPLOADED', 'text' => 'Файл загружен.']],
        ], Actor::user($user));
        QuickPublishJob::dispatch($job->id)->afterCommit();

        return $job;
    }

    /**
     * A held file continues under different options (e.g. «allow a downgrade»): a new run on
     * the same artifact, which picks up from the database state.
     *
     * @param  array<string, mixed>  $options
     */
    public function resume(PipelineJob $held, User $user, array $options): PipelineJob
    {
        $stage = $held->payload['stage'] ?? null;
        if ($held->type !== QuickPublishJob::TYPE || $held->status !== PipelineJobStatus::Succeeded || $stage !== self::STAGE_HELD) {
            throw new ApiException(ErrorCode::Conflict, 'Этот файл не ждёт решения.');
        }
        $payload = $held->payload;
        $payload['options'] = array_replace($payload['options'] ?? self::DEFAULT_OPTIONS, array_intersect_key($options, self::DEFAULT_OPTIONS));
        $payload['requested_by'] = $user->id;
        $payload = array_diff_key($payload, array_flip(['hold_code', 'result', 'clean_job', 'cleaning']));
        $payload['stage'] = 'INSPECTING';
        $payload['message'] = 'Продолжаю с новыми настройками.';

        $artifact = $held->subject;
        if (! $artifact instanceof AppArtifact) {
            throw new ApiException(ErrorCode::Conflict, 'Файл этой публикации больше не существует.');
        }
        $attempt = PipelineJob::query()->where('type', QuickPublishJob::TYPE)->where('subject_id', $held->subject_id)->count() + 1;
        $job = $this->jobs->create(QuickPublishJob::TYPE, QuickPublishJob::idempotencyKey($artifact).':'.$attempt, $artifact, $payload, Actor::user($user));
        QuickPublishJob::dispatch($job->id)->afterCommit();

        return $job;
    }

    /* ---------- Pipeline ---------- */

    /**
     * Advances one file as far as it can go. Returns the job's result code when it is finished
     * (PUBLISHED, DUPLICATE, REJECTED or HELD); throws RetryLater while a step is still running.
     */
    public function run(PipelineJob $job): string
    {
        $source = $job->subject;
        if (! $source instanceof AppArtifact) {
            return 'SUBJECT_MISSING';
        }
        $payload = $job->payload ?? [];
        $user = User::query()->find($payload['requested_by'] ?? null);
        if ($user === null) {
            return $this->finish($job, self::STAGE_FAILED, 'Пользователь, запустивший публикацию, больше не существует.');
        }
        $options = array_replace(self::DEFAULT_OPTIONS, $payload['options'] ?? []);

        $source->refresh();
        if (in_array($source->status, [ArtifactStatus::Uploaded, ArtifactStatus::Hashing, ArtifactStatus::Inspecting], true)) {
            $this->wait($job, 'INSPECTING', 'Идёт проверка файла: структура, подписи, антивирус.');
        }

        $work = $this->cleanedOrOriginal($job, $source, $user, $options);
        if (is_string($work)) {
            return $work;
        }

        return $this->publish($job, $source, $work, $user, $options);
    }

    /**
     * The artifact to publish: the original, or the cleaned copy once cleaning has run.
     * Returns a result code when the file cannot go on.
     *
     * @param  array<string, bool>  $options
     */
    private function cleanedOrOriginal(PipelineJob $job, AppArtifact $source, User $user, array $options): AppArtifact|string
    {
        $copy = $this->copyOf($source);

        if ($copy === null) {
            if ($source->status !== ArtifactStatus::ProvenanceReview) {
                return $this->refused($job, $source);
            }

            $cleanJob = isset($job->payload['clean_job']) ? PipelineJob::query()->where('public_id', $job->payload['clean_job'])->first() : null;
            if ($cleanJob === null) {
                $plan = $this->cleaningPlan($source, $options);
                if (is_string($plan)) {
                    return $this->finish($job, self::STAGE_HELD, $plan, ['hold_code' => 'CLEANING']);
                }
                if ($plan === null) {
                    $this->note($job, 'PUBLISHING', 'Очистка не нужна: сторонних библиотек нет.');

                    return $source;
                }
                try {
                    $cleanJob = $this->cleaning->request($source, User::findOrFail($job->payload['requested_by']), $plan['selection'], 'Быстрая публикация: очистка от сторонних библиотек.', $job->payload['ip'] ?? null);
                } catch (ApiException $refused) {
                    return $this->finish($job, self::STAGE_HELD, $refused->getMessage(), ['hold_code' => 'CLEANING']);
                }
                $this->note($job, 'CLEANING', 'Очистка: убираю '.$this->describe($plan['names']).'.', ['clean_job' => $cleanJob->public_id, 'cleaning' => $plan['names']]);
                $cleanJob->refresh();
            }

            $cleanJob->refresh();
            if (in_array($cleanJob->status, [PipelineJobStatus::Queued, PipelineJobStatus::Leased, PipelineJobStatus::Running, PipelineJobStatus::FailedRetryable], true)) {
                $this->wait($job, 'CLEANING', 'Идёт очистка файла от сторонних библиотек.');
            }
            if ($cleanJob->status !== PipelineJobStatus::Succeeded) {
                return $this->finish($job, self::STAGE_HELD, 'Очистка не удалась: '.($cleanJob->error_message_redacted ?: 'причина неизвестна').'. Файл остаётся на проверке.', ['hold_code' => 'CLEANING']);
            }
            if ($cleanJob->result_code === 'ALREADY_EXISTS') {
                return $this->finish($job, self::STAGE_DUPLICATE, 'Такая очищенная версия уже есть в системе.');
            }
            if ($cleanJob->result_code === 'NOTHING_TO_CLEAN') {
                return $source;
            }
            $copy = $this->copyOf($source);
            if ($copy === null) {
                $this->wait($job, 'CLEANING', 'Очищенная копия создаётся.');
            }
        }

        if (in_array($copy->status, [ArtifactStatus::Uploaded, ArtifactStatus::Hashing, ArtifactStatus::Inspecting], true)) {
            $this->wait($job, 'INSPECTING', 'Проверяю очищенную копию.');
        }
        if ($copy->status !== ArtifactStatus::ProvenanceReview) {
            return $this->refused($job, $copy);
        }

        return $copy;
    }

    /**
     * What to remove, from the inspection's cleaner analysis. A string is a reason to hold,
     * null means there is nothing to clean.
     *
     * @param  array<string, bool>  $options
     * @return array{selection: array<string, mixed>, names: list<string>}|string|null
     */
    private function cleaningPlan(AppArtifact $source, array $options): array|string|null
    {
        $analysis = $source->inspection['cleaning'] ?? null;
        if ($analysis === null) {
            return $options['remove_unknown_libraries']
                ? 'Инструмент очистки IPA недоступен на сервере, поэтому файл не опубликован. Отключите «Удалять сторонние библиотеки», если нужно опубликовать как есть.'
                : null;
        }
        if (isset($analysis['error'])) {
            return 'Анализ файла на сторонние библиотеки не удался: '.$analysis['error'];
        }
        if (($analysis['encrypted_binaries'] ?? []) !== []) {
            return 'В файле есть зашифрованный FairPlay код ('.implode(', ', array_map('basename', $analysis['encrypted_binaries'])).'): это копия из App Store, она не запустится. Нужен расшифрованный IPA.';
        }

        $remove = [];
        $blocked = [];
        foreach ($analysis['modules'] ?? [] as $module) {
            if ($module['recommended'] ?? false) {
                continue;
            }
            if (! $options['remove_unknown_libraries'] || ! in_array($module['category'] ?? '', self::UNWANTED, true)) {
                continue;
            }
            if ($module['removable'] ?? false) {
                $remove[] = (string) $module['path'];
            } else {
                $blocked[] = basename((string) $module['path']).' ('.($module['blocked_reason'] ?? 'нельзя убрать безопасно').')';
            }
        }
        if ($blocked !== []) {
            return 'Не удалось безопасно убрать: '.implode('; ', $blocked).'. Файл остаётся на проверке.';
        }

        $recommended = ($analysis['recommended']['remove'] ?? []) !== [] || ($analysis['recommended']['patch'] ?? []) !== [] || ($analysis['recommended']['fix_metadata'] ?? false);
        if ($remove === [] && ! $recommended) {
            return null;
        }
        $names = array_map('basename', array_merge($analysis['recommended']['remove'] ?? [], $remove));
        if (($analysis['recommended']['fix_metadata'] ?? false) && $names === []) {
            $names = ['исправление Info.plist'];
        }

        return ['selection' => ['remove' => $remove, 'recommended' => $recommended], 'names' => array_values(array_unique($names))];
    }

    /**
     * Listing, approval, publication.
     *
     * @param  array<string, bool>  $options
     */
    private function publish(PipelineJob $job, AppArtifact $source, AppArtifact $work, User $user, array $options): string
    {
        $bundle = (string) $work->bundle_identifier;
        $created = (bool) ($job->payload['created_listing'] ?? false);

        if (isset($job->payload['adopted'])) {
            // An earlier run already put a copy into the matched listing.
            $work = AppArtifact::query()->where('public_id', $job->payload['adopted'])->firstOrFail();
            $target = CatalogApp::withTrashed()->findOrFail($work->app_id);
        } else {
            $home = CatalogApp::withTrashed()->findOrFail($work->app_id);
            $this->note($job, 'PUBLISHING', 'Ищу приложение в каталоге по Bundle ID.');
            if (! str_starts_with($home->slug, self::STAGING_PREFIX)) {
                $target = $home; // The staging listing became the real one on an earlier run.
            } elseif (($target = $this->matchListing($bundle, $home)) !== null) {
                if ($target->source_type !== SourceType::CustomerProvided && ! $options['allow_other_sources']) {
                    return $this->finish($job, self::STAGE_HELD, "Листинг «{$target->name}» создан из другого источника ({$target->source_type->value}). Обновить его автоматически можно только с разрешением.", ['hold_code' => 'OTHER_SOURCE']);
                }
                if (($hold = $this->conflict($job, $target, $work, $options)) !== null) {
                    return $hold;
                }
                $work = $this->adopt($target, $home, $work, $user);
                $this->note($job, 'PUBLISHING', "Нашёл приложение в каталоге: «{$target->name}».", ['adopted' => $work->public_id]);
            } else {
                $target = $this->promote($home, $work);
                if (is_string($target)) {
                    return $this->finish($job, self::STAGE_HELD, $target, ['hold_code' => 'LISTING_EXISTS']);
                }
                $created = true;
                $this->note($job, 'PUBLISHING', "Создан новый листинг «{$target->name}».", ['created_listing' => true]);
            }
        }
        $work->refresh();

        if (! $created && $target->icon_path === null) {
            $this->applyIcon($target, $work, $this->lookup->icon($this->lookup->find($bundle)['icon_url'] ?? null));
        }

        $this->note($job, 'PUBLISHING', "Публикую в каталог: «{$target->name}» {$work->version}.");
        $before = AppArtifact::query()->where('app_id', $target->id)->where('status', ArtifactStatus::Published->value)->pluck('public_id')->all();
        try {
            $this->eligibility->grantFor($target, $user, 'Быстрая публикация администратором.');
            $this->review->approve($work, $user, 'Быстрая публикация: автоматические проверки пройдены.', self::CHECKLIST, scanAcknowledged: false);
            $work->refresh();
            if ($work->status !== ArtifactStatus::Ready) {
                return $this->finish($job, self::STAGE_REJECTED, 'Файл не прошёл проверку совместимости: '.($work->status_reason ?? $work->status->value).'.');
            }
            $this->review->publish($work, $user);
        } catch (ApiException|IllegalStateTransition $refused) {
            return $this->finish($job, self::STAGE_HELD, $this->explain($refused), ['hold_code' => 'REVIEW']);
        }

        $work->refresh();
        $result = [
            'app_id' => $target->public_id,
            'name' => $target->name,
            'artifact_id' => $work->public_id,
            'version' => $work->version,
            'build_number' => $work->build_number,
            'action' => $created ? 'created' : 'updated',
            'category' => $target->category?->title,
            'superseded' => $before,
            'removed_libraries' => array_values(array_map(fn ($row) => basename((string) ($row['path'] ?? '')), $work->cleaning_report['removed_modules'] ?? [])),
            'visibility' => $target->refresh()->visibility->value,
        ];
        $this->audit->record('quick_publish.published', $work, after: $result + ['source_artifact_id' => $source->public_id], actor: Actor::user($user));

        return $this->finish($job, self::STAGE_PUBLISHED, $created ? "Создан листинг «{$target->name}» и опубликована версия {$work->version}." : "Обновлён «{$target->name}»: версия {$work->version}.", ['result' => $result]);
    }

    /* ---------- Listing ---------- */

    private function matchListing(string $bundle, CatalogApp $staging): ?CatalogApp
    {
        $candidates = CatalogApp::query()
            ->whereKeyNot($staging->id)
            ->where('source_type', '!=', SourceType::UserImport->value)
            ->whereHas('artifacts', fn ($query) => $query->where('bundle_identifier', $bundle)->whereNotIn('status', self::DISCARDED))
            ->orderByDesc('id')
            ->get();

        return $candidates->first(fn (CatalogApp $app) => $app->artifacts()->where('status', ArtifactStatus::Published->value)->exists())
            ?? $candidates->first();
    }

    /**
     * A reason this build must not replace what is live, as a finished job's result code;
     * null when it may go on.
     *
     * @param  array<string, bool>  $options
     */
    private function conflict(PipelineJob $job, CatalogApp $target, AppArtifact $work, array $options): ?string
    {
        $same = AppArtifact::query()->where('app_id', $target->id)->where('sha256', $work->sha256)->whereKeyNot($work->id)
            ->whereNotIn('status', self::DISCARDED)->first();
        if ($same !== null) {
            $this->discard($work, 'Эта версия уже есть в каталоге.');

            return $this->finish($job, self::STAGE_DUPLICATE, "Эта версия уже есть в «{$target->name}».");
        }

        $live = $target->artifacts()->where('status', ArtifactStatus::Published->value)->orderByDesc('id')->first();
        if ($live !== null && ! $options['allow_downgrade'] && $this->older($work, $live)) {
            return $this->finish($job, self::STAGE_HELD, "Версия {$work->version} ({$work->build_number}) старше опубликованной {$live->version} ({$live->build_number}). Понижение версии нужно разрешить.", ['hold_code' => 'DOWNGRADE']);
        }

        return null;
    }

    private function older(AppArtifact $new, AppArtifact $live): bool
    {
        $byVersion = version_compare((string) $new->version, (string) $live->version);

        return $byVersion < 0 || ($byVersion === 0 && version_compare((string) $new->build_number, (string) $live->build_number) < 0);
    }

    /**
     * Puts the build into the matched listing. An artifact cannot change listing (its identity is
     * locked in the database), so, as for a cleaned copy, a new artifact for the same file is
     * created there; it carries the inspection already done, and the staged one is retired.
     */
    private function adopt(CatalogApp $target, CatalogApp $staging, AppArtifact $work, User $user): AppArtifact
    {
        return DB::transaction(function () use ($target, $staging, $work, $user) {
            $actor = Actor::user($user);
            $copy = AppArtifact::create([
                'app_id' => $target->id,
                'derived_from_artifact_id' => $work->id,
                'sha256' => $work->sha256,
                'size_bytes' => $work->size_bytes,
                'storage_disk' => $work->storage_disk,
                'storage_path' => $work->storage_path,
                'original_filename' => $work->original_filename,
                'source_type' => $target->source_type,
                'uploaded_by' => $user->id,
                'declaration_version' => $work->getAttribute('declaration_version'),
                'declaration_accepted_at' => $work->getAttribute('declaration_accepted_at'),
                'declaration_ip' => $work->getAttribute('declaration_ip'),
                'cleaning_report' => $work->cleaning_report,
                'status' => ArtifactStatus::Uploaded,
            ]);
            $this->states->transition($copy, ArtifactStatus::Hashing, actor: $actor);
            $this->states->transition($copy, ArtifactStatus::Inspecting, actor: $actor);
            $copy->forceFill([
                'bundle_identifier' => $work->bundle_identifier,
                'version' => $work->version,
                'build_number' => $work->build_number,
                'min_ios_version' => $work->min_ios_version,
                'inspection' => ($work->inspection ?? []) + ['adopted_from' => $work->public_id, 'adopted_at' => now()->toIso8601ZuluString()],
            ])->save();
            $this->states->transition($copy, ArtifactStatus::ProvenanceReview, actor: $actor);

            // The staged file leaves the review queue; it stays as the evidence of what was uploaded.
            $this->states->transition($work, ArtifactStatus::ProvenanceFailed, 'Moved to listing '.$target->public_id, $actor, extra: ['status_reason' => 'MOVED_TO_LISTING']);
            $staging->delete();
            $this->audit->record('quick_publish.matched', $copy, after: ['listing' => $target->public_id, 'staged_artifact' => $work->public_id], actor: $actor);

            return $copy;
        });
    }

    /**
     * Turns the staging listing into the real one. Returns a reason when it cannot.
     */
    private function promote(CatalogApp $staging, AppArtifact $work): CatalogApp|string
    {
        $bundle = (string) $work->bundle_identifier;
        $key = substr(hash('sha256', $bundle), 0, 20);
        if (CatalogApp::withTrashed()->where('slug', 'tg-'.$key)->exists()) {
            return "Листинг для Bundle ID {$bundle} уже существует, но удалён или скрыт. Восстановите его в разделе «Приложения» и загрузите файл снова.";
        }

        $meta = $this->lookup->find($bundle);
        $name = $meta['name'] ?? (is_string($work->inspection['bundle']['name'] ?? null) ? trim($work->inspection['bundle']['name']) : '');
        $category = ($meta['category_slug'] ?? null) !== null ? AppCategory::query()->where('slug', $meta['category_slug'])->first() : null;
        $fields = [
            'slug' => 'tg-'.$key,
            'name' => Str::limit($name !== '' ? $name : $staging->name, 60, ''),
            'bundle_identifier' => config('storefront.artifacts.own_bundle_prefix').'tg'.$key,
            'category_id' => ($category ?? $this->category('imported'))->id,
            'publisher_id' => $this->publisher($meta['publisher'] ?? 'Разработчик не указан')->id,
        ];
        $storeId = $meta['app_store_id'] ?? null;
        if ($storeId !== null && ! CatalogApp::withTrashed()->where('app_store_id', $storeId)->whereKeyNot($staging->id)->exists()) {
            $fields['app_store_id'] = $storeId;
        }
        $staging->forceFill($fields)->save();

        $this->applyIcon($staging, $work, $this->lookup->icon($meta['icon_url'] ?? null));

        return $staging->refresh();
    }

    /** The store's artwork when there is one, otherwise the largest icon inside the IPA. */
    private function applyIcon(CatalogApp $app, AppArtifact $work, ?string $png): void
    {
        if ($png === null) {
            $file = LocalArtifactFile::open(Storage::disk($work->storage_disk), $work->storage_path);
            try {
                $png = $this->icons->extract($file->path);
            } finally {
                $file->release();
            }
        }
        if ($png === null) {
            return;
        }

        $public = Storage::disk('public');
        $temporary = 'catalog-import/quick-'.Str::lower((string) Str::ulid()).'.png';
        $public->put($temporary, $png);
        try {
            $icon = $this->images->optimizeStoredIcon($temporary, $app->public_id);
            if ($icon !== null) {
                $app->forceFill(['icon_path' => $icon['path']])->save();
            }
        } catch (Throwable) {
            // A listing without an icon is publishable; the operator can add one on the card.
        } finally {
            $public->delete($temporary);
        }
    }

    /* ---------- Helpers ---------- */

    private function copyOf(AppArtifact $source): ?AppArtifact
    {
        return AppArtifact::query()->where('derived_from_artifact_id', $source->id)->orderByDesc('id')->first();
    }

    /** The file failed inspection, was quarantined or rejected. */
    private function refused(PipelineJob $job, AppArtifact $artifact): string
    {
        $inspection = $artifact->inspection ?? [];
        $reason = match (true) {
            $artifact->status === ArtifactStatus::Quarantined => 'Антивирус пометил файл как опасный: '.($inspection['malware_scan']['signature'] ?? $inspection['malware_scan']['status'] ?? 'без подробностей').'.',
            isset($inspection['failure']['message']) => (string) $inspection['failure']['message'],
            default => 'Файл не прошёл проверку ('.($artifact->status_reason ?? $artifact->status->value).').',
        };
        $code = $inspection['failure']['code'] ?? $artifact->status_reason;
        $hint = match ($code) {
            'VERSION_EXISTS' => ' Такая версия уже есть у приложения.',
            'DUPLICATE_ARTIFACT' => '',
            default => '',
        };

        return $this->finish($job, self::STAGE_REJECTED, $reason.$hint, ['reason_code' => $code]);
    }

    /** Takes a duplicate out of the review queue. */
    private function discard(AppArtifact $artifact, string $reason): void
    {
        try {
            $this->review->reject($artifact, User::query()->findOrFail($artifact->uploaded_by), $reason);
        } catch (Throwable) {
            // Already out of review.
        }
    }

    private function explain(ApiException|IllegalStateTransition $refused): string
    {
        if ($refused instanceof IllegalStateTransition) {
            return $refused->getMessage() ?: 'Публикация остановлена: недопустимое состояние файла.';
        }
        if ($refused->errorCode === ErrorCode::Forbidden) {
            return 'Включена проверка вторым сотрудником: файл должен одобрить другой человек во вкладке «Проверка происхождения».';
        }
        if (($refused->details['code'] ?? null) === 'TEAM_NOT_ELIGIBLE') {
            return 'Ни одна команда Apple не одобрена для этого приложения. Одобрите Bundle ID в разделе «Команды Apple» и повторите.';
        }
        if ($refused->errorCode === ErrorCode::ValidationFailed) {
            return 'Антивирусная проверка не подтвердила, что файл чист, поэтому он не опубликован автоматически. Проверьте его вручную.';
        }

        return $refused->getMessage();
    }

    /** @param  list<string>  $names */
    private function describe(array $names): string
    {
        return count($names) > 4 ? implode(', ', array_slice($names, 0, 4)).' и ещё '.(count($names) - 4) : implode(', ', $names);
    }

    private function wait(PipelineJob $job, string $stage, string $message): never
    {
        $this->note($job, $stage, $message);

        throw new RetryLater($message, 6);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function finish(PipelineJob $job, string $stage, string $message, array $extra = []): string
    {
        $this->note($job, $stage, $message, $extra);

        return $stage;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function note(PipelineJob $job, string $stage, string $message, array $extra = []): void
    {
        $payload = $job->payload ?? [];
        $steps = $payload['steps'] ?? [];
        if (($payload['message'] ?? null) !== $message) {
            $steps[] = ['at' => now()->toIso8601ZuluString(), 'stage' => $stage, 'text' => $message];
        }
        $job->forceFill(['payload' => array_merge($payload, $extra, ['stage' => $stage, 'message' => $message, 'steps' => array_slice($steps, -40)])])->save();
    }

    private function category(string $slug): AppCategory
    {
        return AppCategory::firstOrCreate(['slug' => $slug], ['title' => 'Импортировано', 'kind' => 'APPS', 'sort_order' => 999]);
    }

    private function publisher(string $name): AppPublisher
    {
        return AppPublisher::firstOrCreate(['name' => Str::limit($name, 120, '')]);
    }

    private function nameFromFilename(string $filename): string
    {
        $name = trim((string) preg_replace('/[_]+/', ' ', (string) preg_replace('/\.ipa$/i', '', basename($filename))));

        return $name === '' ? 'Новое приложение' : Str::limit($name, 60, '');
    }
}
