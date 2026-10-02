<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ArtifactStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AppArtifact;
use App\Models\ArtifactReview;
use App\Models\AuditLog;
use App\Models\PipelineJob;
use App\Models\ProvenanceDocument;
use App\Services\Artifacts\ArtifactCleaningService;
use App\Services\Artifacts\ArtifactReviewService;
use App\Services\Artifacts\IpaCleaner;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Review queue, quarantine and publishing of uploaded IPAs (IMPLEMENTATION_PLAN P5-BE-03).
 */
class ArtifactController extends Controller
{
    public function __construct(
        private readonly ArtifactReviewService $reviews,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::enum(ArtifactStatus::class)],
            'app_id' => ['nullable', 'string'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AppArtifact::query()->with(['app' => fn ($app) => $app->withTrashed(), 'uploader'])->latest('id');

        if (filled($filters['status'] ?? null)) {
            $query->whereIn('status', $filters['status']);
        }
        if (filled($filters['app_id'] ?? null)) {
            $query->whereHas('app', fn (Builder $app) => $app->withTrashed()->where('public_id', strtolower($filters['app_id'])));
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn (Builder $where) => $where
                ->where('original_filename', 'like', $term)
                ->orWhere('bundle_identifier', 'like', $term)
                ->orWhere('sha256', strtolower($filters['q'])));
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($page, array_map(fn (AppArtifact $artifact) => $this->summary($artifact), $page->items()));
    }

    public function show(Request $request, AppArtifact $artifact): JsonResponse
    {
        $artifact->load(['app' => fn ($app) => $app->withTrashed(), 'uploader', 'appVersion', 'reviews.reviewer', 'documents', 'pipelineJobs.subject', 'derivedFrom']);

        $history = AuditLog::query()
            ->where('subject_type', 'app_artifact')
            ->where('subject_id', $artifact->public_id)
            ->latest('id')
            ->limit(50)
            ->get();

        return ApiResponse::ok($this->summary($artifact) + [
            'declaration' => [
                'version' => $artifact->declaration_version,
                'accepted_at' => $artifact->declaration_accepted_at->toIso8601ZuluString(),
            ],
            'app_version_id' => $artifact->appVersion?->public_id,
            'inspection' => $artifact->inspection,
            // tools/ipa-cleaner: where a cleaned copy came from and what was removed, and the copies made from this one.
            'derived_from' => $artifact->derivedFrom ? ['id' => $artifact->derivedFrom->public_id, 'sha256' => $artifact->derivedFrom->sha256] : null,
            'cleaning_report' => $artifact->cleaning_report,
            'cleaned_copies' => AppArtifact::query()->where('derived_from_artifact_id', $artifact->id)->latest('id')->get()
                ->map(fn (AppArtifact $copy) => ['id' => $copy->public_id, 'status' => $copy->status->value, 'created_at' => $copy->created_at?->toIso8601ZuluString()])->all(),
            'reviews' => $artifact->reviews->map(fn (ArtifactReview $review) => [
                'id' => $review->public_id,
                'decision' => $review->decision,
                'from_status' => $review->from_status,
                'to_status' => $review->to_status,
                'reason' => $review->reason,
                'checklist' => $review->checklist,
                'scan_result_acknowledged' => $review->scan_result_acknowledged,
                'reviewer' => $review->reviewer?->email,
                'created_at' => $review->created_at->toIso8601ZuluString(),
            ])->all(),
            'documents' => $artifact->documents->map(fn (ProvenanceDocument $document) => $this->document($artifact, $document))->all(),
            'jobs' => $artifact->pipelineJobs->map(fn (PipelineJob $job) => JobController::present($job))->all(),
            'available_actions' => $this->actions($artifact, $request),
            'review_checklist' => [
                'version' => config('storefront.artifacts.review_checklist_version'),
                'items' => config('storefront.artifacts.review_checklist'),
            ],
            'history' => $history->map(fn (AuditLog $entry) => (new AuditLogResource($entry))->resolve($request))->all(),
        ]);
    }

    public function review(Request $request, AppArtifact $artifact): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['boolean'],
            'acknowledge_scan_result' => ['nullable', 'boolean'],
        ]);

        $review = $data['decision'] === 'approve'
            ? $this->reviews->approve($artifact, $request->user(), $data['reason'] ?? null, $data['checklist'] ?? [], (bool) ($data['acknowledge_scan_result'] ?? false))
            : $this->reviews->reject($artifact, $request->user(), $data['reason'] ?? null);

        return ApiResponse::ok([
            'review_id' => $review->public_id,
            'decision' => $review->decision,
            'artifact' => $this->summary($artifact->refresh()),
        ]);
    }

    public function publish(Request $request, AppArtifact $artifact): JsonResponse
    {
        return ApiResponse::ok($this->summary($this->reviews->publish($artifact, $request->user())->refresh()));
    }

    public function revoke(Request $request, AppArtifact $artifact): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return ApiResponse::ok($this->summary($this->reviews->revoke($artifact, $request->user(), $data['reason'])->refresh()));
    }

    public function inspect(Request $request, AppArtifact $artifact): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:2000']]);
        $job = $this->reviews->reinspect($artifact, $request->user(), $data['reason'] ?? null);

        return ApiResponse::ok(['artifact' => $this->summary($artifact->refresh()), 'job' => JobController::present($job->refresh())], 202);
    }

    /**
     * A cleaned copy made by tools/ipa-cleaner: the reviewed promotions (recommended), and/or
     * chosen modules, extensions, opt-in mods and metadata fixes from the inspection report.
     */
    public function clean(Request $request, AppArtifact $artifact, ArtifactCleaningService $cleaning): JsonResponse
    {
        $data = $request->validate([
            'recommended' => ['nullable', 'boolean'],
            'remove' => ['nullable', 'array', 'max:100'],
            'remove.*' => ['string', 'max:1024'],
            'remove_extensions' => ['nullable', 'array', 'max:50'],
            'remove_extensions.*' => ['string', 'max:1024'],
            'opt_in' => ['nullable', 'array', 'max:20'],
            'opt_in.*' => ['string', 'max:64'],
            'fix_metadata' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $job = $cleaning->request($artifact, $request->user(), $data, $data['reason'], $request->ip());

        return ApiResponse::ok(['artifact' => $this->summary($artifact->refresh()), 'job' => JobController::present($job->refresh())], 202);
    }

    public function storeDocument(Request $request, AppArtifact $artifact): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:'.config('storefront.artifacts.document_max_kilobytes'), 'mimes:'.implode(',', config('storefront.artifacts.document_mimes'))],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $file = $data['file'];
        $extension = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension());
        $path = $file->storeAs("provenance/{$artifact->public_id}", Str::ulid().'.'.$extension, 'artifacts');

        $document = ProvenanceDocument::create([
            'artifact_id' => $artifact->id,
            'uploaded_by' => $request->user()->id,
            'original_filename' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'sha256' => hash_file('sha256', $file->getRealPath()),
            'storage_path' => (string) $path,
            'description' => $data['description'] ?? null,
        ]);

        $this->audit->record('artifact.document_added', $artifact, after: [
            'document_id' => $document->public_id,
            'filename' => $document->original_filename,
            'sha256' => $document->sha256,
        ]);

        return ApiResponse::ok($this->document($artifact, $document), 201);
    }

    public function showDocument(AppArtifact $artifact, string $document): StreamedResponse
    {
        $model = $artifact->documents()->where('public_id', strtolower($document))->firstOrFail();
        $this->audit->record('artifact.document_downloaded', $artifact, after: ['document_id' => $model->public_id]);

        return Storage::disk('artifacts')->download($model->storage_path, $model->original_filename, [
            'Content-Type' => $model->mime_type,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(AppArtifact $artifact): array
    {
        $app = $artifact->app()->withTrashed()->first();

        return [
            'id' => $artifact->public_id,
            'app' => $app === null ? null : ['id' => $app->public_id, 'name' => $app->name, 'source_type' => $app->source_type->value],
            'status' => $artifact->status->value,
            'status_reason' => $artifact->status_reason,
            'original_filename' => $artifact->original_filename,
            'size_bytes' => $artifact->size_bytes,
            'sha256' => $artifact->sha256,
            'source_type' => $artifact->source_type->value,
            'bundle_identifier' => $artifact->bundle_identifier,
            'version' => $artifact->version,
            'build_number' => $artifact->build_number,
            'min_ios_version' => $artifact->min_ios_version,
            'compatibility_issues' => array_column($artifact->inspection['compatibility_issues'] ?? [], 'code'),
            'malware_scan' => $artifact->inspection['malware_scan']['status'] ?? null,
            'uploaded_by' => $artifact->uploader?->email,
            'created_at' => $artifact->created_at?->toIso8601ZuluString(),
            'updated_at' => $artifact->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function document(AppArtifact $artifact, ProvenanceDocument $document): array
    {
        return [
            'id' => $document->public_id,
            'filename' => $document->original_filename,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'sha256' => $document->sha256,
            'description' => $document->description,
            'download_url' => url("/api/v1/admin/artifacts/{$artifact->public_id}/documents/{$document->public_id}"),
            'created_at' => $document->created_at->toIso8601ZuluString(),
        ];
    }

    /**
     * What the caller can do next, so the admin UI never offers an illegal move.
     *
     * @return list<string>
     */
    private function actions(AppArtifact $artifact, Request $request): array
    {
        if (! Gate::forUser($request->user())->allows('artifacts.manage')) {
            return [];
        }

        $actions = match ($artifact->status) {
            ArtifactStatus::ProvenanceReview => ['approve', 'reject'],
            ArtifactStatus::Quarantined => ['release', 'reject'],
            ArtifactStatus::Ready => ['publish', 'revoke'],
            ArtifactStatus::Published => ['revoke'],
            ArtifactStatus::InspectionFailed => ['inspect'],
            default => [],
        };
        if (ArtifactCleaningService::cleanable($artifact) && app(IpaCleaner::class)->enabled()) {
            $actions[] = 'clean';
        }

        return $actions;
    }
}
