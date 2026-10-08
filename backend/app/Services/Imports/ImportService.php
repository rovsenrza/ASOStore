<?php

namespace App\Services\Imports;

use App\Enums\AppVisibility;
use App\Enums\ArtifactStatus;
use App\Enums\ErrorCode;
use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Jobs\FetchImportJob;
use App\Models\AppArtifact;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\Artifacts\ArtifactPurger;
use App\Services\Artifacts\ArtifactReviewService;
use App\Services\Artifacts\ChunkedUploadService;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\TeamEligibilityGranter;
use App\Services\Pipeline\PipelineJobService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customer self-import of an IPA (from Files or a link). An import is a HIDDEN app owned by the
 * customer: the file goes through the same inspection and signing as any upload, but it is never
 * shown in the public catalog and only its owner can install it. Reuses the chunked upload,
 * inspection and review/publish pipeline; this service adds the ownership, limits and finalisation.
 */
class ImportService
{
    /** Signed on an Apple certificate for one device, so an import is capped to protect the cert. */
    public const MAX_BYTES = 3 * 1024 ** 3;

    public const DECLARATION = '2026-10-import-v1';

    public function __construct(
        private readonly AuditService $audit,
        private readonly TeamEligibilityGranter $eligibility,
        private readonly ArtifactReviewService $review,
        private readonly ChunkedUploadService $uploads,
        private readonly PipelineJobService $jobs,
        private readonly ArtifactPurger $purger,
        private readonly LinkFetcher $fetcher,
    ) {}

    private function dailyLimit(): int
    {
        return (int) config('storefront.imports.daily_limit', 10);
    }

    private function totalLimit(): int
    {
        return (int) config('storefront.imports.total_limit', 30);
    }

    /**
     * Opens a chunked upload for a new import, after checking the per-customer limits. The upload
     * is attached to a fresh hidden app owned by the customer; completing it (ChunkedUploadService)
     * creates the artifact and inspects it like any other.
     */
    public function start(User $user, string $filename, int $sizeBytes, ?string $sha256, ?string $ip): UploadSession
    {
        if (preg_match('/\.ipa$/i', $filename) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['filename' => 'Нужен файл .ipa.']);
        }
        if ($sizeBytes < 1 || $sizeBytes > self::MAX_BYTES) {
            throw new ApiException(ErrorCode::ValidationFailed, details: ['size_bytes' => 'Файл слишком большой.']);
        }
        // Picking the same file again (after the app was closed mid-upload, say) continues the
        // upload already open for it instead of sending everything again as a new import.
        $resumable = $this->resumableUpload($user, $sizeBytes, $sha256);
        if ($resumable !== null) {
            return $resumable;
        }
        $this->assertWithinLimits($user);

        return DB::transaction(function () use ($user, $filename, $sizeBytes, $sha256, $ip) {
            $app = $this->createOwnedApp($user, $filename);
            $upload = $this->openUpload($app, $user, $filename, $sizeBytes, $sha256, $ip);
            $this->audit->record('import.started', $app, after: [
                'filename' => $upload->original_filename,
                'size_bytes' => $sizeBytes,
            ], actor: Actor::user($user));

            return $upload;
        });
    }

    /**
     * Starts an import from a link: the hidden app is created now (so it shows in the customer's
     * list at once) and the server downloads the file in FetchImportJob, which then feeds it to
     * the same upload and inspection as an import from Files.
     */
    public function startFromLink(User $user, string $url, ?string $ip): CatalogApp
    {
        try {
            $url = $this->fetcher->normalize($url);
        } catch (LinkFetchFailed $invalid) {
            throw new ApiException(ErrorCode::ValidationFailed, $invalid->getMessage(), ['url' => $invalid->getMessage()]);
        }
        $this->assertWithinLimits($user);

        return DB::transaction(function () use ($user, $url, $ip) {
            $filename = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
            $app = $this->createOwnedApp($user, preg_match('/\.ipa$/i', $filename) === 1 ? $filename : 'Импорт по ссылке.ipa');
            $job = $this->jobs->create(FetchImportJob::TYPE, FetchImportJob::idempotencyKey($app), $app, [
                'import_id' => $app->public_id,
                'url' => $url,
                'ip' => $ip,
            ], Actor::user($user), maxAttempts: 3);
            FetchImportJob::dispatch($job->id)->afterCommit();
            $this->audit->record('import.link_started', $app, after: ['host' => parse_url($url, PHP_URL_HOST)], actor: Actor::user($user));

            return $app;
        });
    }

    /**
     * Feeds a file the server downloaded for a link import through the chunked upload, so it is
     * stored, hashed and inspected exactly like one sent from the device.
     */
    public function ingest(CatalogApp $app, string $path, string $filename, int $sizeBytes, string $sha256): AppArtifact
    {
        if ($sizeBytes > self::MAX_BYTES) {
            throw new LinkFetchFailed('Файл по ссылке больше 3 ГБ.');
        }
        $owner = User::query()->findOrFail($app->imported_by_user_id);
        $job = $app->pipelineJobs()->where('type', FetchImportJob::TYPE)->latest('id')->first();
        $upload = $this->openUpload($app, $owner, $filename, $sizeBytes, $sha256, $job?->payload['ip'] ?? null);

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw LinkFetchFailed::retryable('Сервер временно не может прочитать файл.');
        }
        try {
            for ($number = 0; $number < $upload->chunk_count; $number++) {
                $this->uploads->storeChunk($upload, $number, (string) fread($handle, $upload->chunk_size), null);
            }

            return $this->uploads->complete($upload);
        } catch (ApiException $refused) {
            throw new LinkFetchFailed($refused->errorCode === ErrorCode::UploadCorrupt
                ? 'Файл по ссылке скачался с ошибкой.'
                : 'Файл по ссылке не удалось принять.');
        } finally {
            fclose($handle);
        }
    }

    /**
     * Deletes an import: its installs end, its IPA and signed copies are removed from storage
     * (ArtifactPurger), and the hidden app is soft-deleted so the slot is freed.
     */
    public function delete(CatalogApp $app, User $owner): void
    {
        $app->delete();
        $this->audit->record('import.deleted', $app, actor: Actor::user($owner));
        $this->purger->purgeApp($app, Actor::user($owner), 'Import deleted by its owner.');
    }

    /**
     * Makes an inspected import installable for its owner: grants team eligibility for its own
     * bundle ID, approves it (the owner is its reviewer) and publishes it. The app stays HIDDEN;
     * only the PUBLISHED artifact matters, and only the owner's install route reaches it.
     */
    public function finalize(CatalogApp $app, User $owner): AppArtifact
    {
        $published = $app->publishedArtifact()->first();
        if ($published !== null) {
            return $published;
        }
        // Inspection quarantines an infected or unreadable file before it reaches review, so an
        // import that is not in review is still being checked, or did not pass.
        $artifact = $app->artifacts()->latest('id')->first();
        if ($artifact === null) {
            throw new ApiException(ErrorCode::ArtifactNotInstallable);
        }
        if ($artifact->status !== ArtifactStatus::ProvenanceReview) {
            throw new ApiException(ErrorCode::Conflict, 'Импорт ещё проверяется или не прошёл проверку.', ['status' => $artifact->status->value]);
        }

        $this->nameFromInspection($app, $artifact);
        $this->eligibility->grantFor($app, $owner, 'Customer self-import.');
        $this->review->approve($artifact, $owner, 'Imported by the owner for personal installation.', [
            'source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true,
        ], scanAcknowledged: true);
        $artifact->refresh();
        if ($artifact->status !== ArtifactStatus::Ready) {
            throw new ApiException(ErrorCode::Conflict, 'Импортированное приложение не готово к установке.', ['status' => $artifact->status->value]);
        }

        return $this->review->publish($artifact, $owner);
    }

    private function openUpload(CatalogApp $app, User $user, string $filename, int $sizeBytes, ?string $sha256, ?string $ip): UploadSession
    {
        $chunkSize = UploadSession::CHUNK_SIZE;

        return UploadSession::create([
            'app_id' => $app->id,
            'uploaded_by' => $user->id,
            'original_filename' => basename($filename),
            'expected_size' => $sizeBytes,
            'expected_sha256' => $sha256 !== null ? strtolower($sha256) : null,
            'chunk_size' => $chunkSize,
            'chunk_count' => (int) ceil($sizeBytes / $chunkSize),
            'source_type' => SourceType::UserImport->value,
            'declaration_version' => self::DECLARATION,
            'declaration_accepted_at' => now(),
            'declaration_ip' => $ip,
            'status' => 'OPEN',
            'expires_at' => now()->addDay(),
        ]);
    }

    private function resumableUpload(User $user, int $sizeBytes, ?string $sha256): ?UploadSession
    {
        if ($sha256 === null) {
            return null;
        }

        // `app` excludes deleted imports: deleting one is how a customer starts over on purpose.
        return UploadSession::query()
            ->where('uploaded_by', $user->id)
            ->where('source_type', SourceType::UserImport->value)
            ->where('expected_sha256', strtolower($sha256))
            ->where('expected_size', $sizeBytes)
            ->where('status', 'OPEN')
            ->where('expires_at', '>', now())
            ->whereHas('app', fn ($query) => $query->where('imported_by_user_id', $user->id))
            ->latest('id')
            ->first();
    }

    private function assertWithinLimits(User $user): void
    {
        // Deleted imports still count toward today, so delete-and-retry cannot get round the daily cap,
        // unless their file never reached the server: nothing was stored or signed for an abandoned
        // or failed upload, and counting it punishes a dropped connection rather than abuse.
        $today = CatalogApp::withTrashed()
            ->where('imported_by_user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->where(fn ($query) => $query->whereNull('deleted_at')->orWhereHas('artifacts'))
            ->count();
        $total = CatalogApp::query()->where('imported_by_user_id', $user->id)->count();
        if ($today >= $this->dailyLimit() || $total >= $this->totalLimit()) {
            throw new ApiException(ErrorCode::QuotaExhausted, 'Достигнут предел импортов. Удалите старый импорт или попробуйте позже.');
        }
    }

    private function createOwnedApp(User $user, string $filename): CatalogApp
    {
        $key = strtolower((string) Str::ulid());

        return CatalogApp::create([
            'slug' => 'import-'.$key,
            'name' => $this->nameFromFilename($filename),
            // Signed under our own prefix, so the primary team is granted automatically.
            'bundle_identifier' => config('storefront.artifacts.own_bundle_prefix').'import'.substr($key, -16),
            'category_id' => AppCategory::firstOrCreate(['slug' => 'imported'], ['title' => 'Импортировано', 'kind' => 'APPS', 'sort_order' => 999])->id,
            'publisher_id' => AppPublisher::firstOrCreate(['name' => 'Импорт'])->id,
            'source_type' => SourceType::UserImport,
            'visibility' => AppVisibility::Hidden,
            'imported_by_user_id' => $user->id,
        ]);
    }

    private function nameFromFilename(string $filename): string
    {
        $name = trim(preg_replace('/\.ipa$/i', '', basename($filename)));
        $name = trim((string) preg_replace('/[_]+/', ' ', $name));

        return $name === '' ? 'Импортированное приложение' : Str::limit($name, 60, '');
    }

    private function nameFromInspection(CatalogApp $app, AppArtifact $artifact): void
    {
        $name = $artifact->inspection['bundle']['name'] ?? null;
        if (is_string($name) && trim($name) !== '') {
            $app->forceFill(['name' => Str::limit(trim($name), 60, '')])->save();
        }
    }
}
