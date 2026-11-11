<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\DeviceRegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppDetailResource;
use App\Http\Resources\AppSummaryResource;
use App\Http\Resources\VersionResource;
use App\Http\Responses\ApiResponse;
use App\Models\CatalogApp;
use App\Services\Devices\CurrentDevice;
use App\Services\Signing\BuildWarmup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AppController extends Controller
{
    /** Apps per shelf on the app page («Похожие приложения», «Ещё от издателя»). */
    private const SHELF_LIMIT = 12;

    /** The placeholder publisher of listings whose developer is not known. */
    private const UNKNOWN_PUBLISHER = 'Разработчик не указан';

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:64'],
            'kind' => ['nullable', 'in:apps,games'],
            // featured (default: editor's picks, then most downloaded), updated (newest release first),
            // new (newest listing first), name (A–Я)
            'sort' => ['nullable', 'in:featured,updated,new,name'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('storefront.catalog.per_page_max')],
        ]);

        $query = CatalogApp::query()
            ->visibleToCustomers()
            ->with(AppSummaryResource::RELATIONS);

        match ($validated['sort'] ?? 'featured') {
            'name' => $query->orderBy('name')->orderBy('id'),
            'updated' => $query->withMax('versions', 'released_at')->orderByDesc('versions_max_released_at')->orderByDesc('created_at')->orderBy('id'),
            'new' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->withDownloads()->orderByRaw('featured_rank IS NULL, featured_rank')->orderByDesc('downloads')->orderBy('name'),
        };

        if (filled($validated['kind'] ?? null)) {
            $query->whereHas('category', fn (Builder $category) => $category->where('kind', strtoupper($validated['kind'])));
        }

        if (filled($validated['q'] ?? null)) {
            $term = '%'.addcslashes($validated['q'], '%_\\').'%';
            $query->where(fn (Builder $where) => $where
                ->where('name', 'like', $term)
                ->orWhere('subtitle', 'like', $term)
                ->orWhereHas('publisher', fn (Builder $publisher) => $publisher->where('name', 'like', $term))
                ->orWhereHas('category', fn (Builder $category) => $category->where('title', 'like', $term)));
        }

        if (filled($validated['category'] ?? null)) {
            $query->whereHas('category', fn (Builder $category) => $category->where('slug', $validated['category']));
        }

        $page = $query->paginate($validated['per_page'] ?? config('storefront.catalog.per_page_default'));

        return ApiResponse::paginated($page, AppSummaryResource::collection($page->items())->resolve($request));
    }

    public function show(Request $request, string $app): JsonResponse
    {
        $model = $this->findVisible($app)->load([...AppSummaryResource::RELATIONS, 'screenshots']);
        $this->warmBuild($model, $request);

        return ApiResponse::ok([
            ...(new AppDetailResource($model))->resolve($request),
            ...$this->pageExtras($model, $request),
        ]);
    }

    /**
     * What the app page shows around the listing itself: how many devices installed it, its
     * public web page, recent versions, and other apps to look at. All additive: older app
     * builds ignore these keys.
     *
     * @return array<string, mixed>
     */
    private function pageExtras(CatalogApp $app, Request $request): array
    {
        $summaries = fn (array $ids): array => $ids === [] ? [] : AppSummaryResource::collection(
            CatalogApp::query()->visibleToCustomers()->whereIn('id', $ids)->with(AppSummaryResource::RELATIONS)->get()
                ->sortBy(fn (CatalogApp $other) => array_search($other->id, $ids, true))->values()
        )->resolve($request);

        // Ranked by downloads; the ranking is the same for everyone, so only the IDs are cached.
        $ranked = fn (string $key, Builder $query): array => Cache::remember(
            "catalog:app-page:{$key}:{$app->id}",
            now()->addMinutes(15),
            fn () => $query->where('id', '!=', $app->id)->publiclyBrowsable()->withDownloads()
                ->orderByDesc('downloads')->orderBy('name')->limit(self::SHELF_LIMIT)->pluck('id')->all(),
        );

        $related = $ranked('related', CatalogApp::query()->where('category_id', $app->category_id));
        $fromPublisher = $app->publisher->name === self::UNKNOWN_PUBLISHER ? [] : $ranked(
            'publisher',
            CatalogApp::query()->where('publisher_id', $app->publisher_id),
        );

        return [
            // Cast on the way out: Redis hands a cached integer back as a string.
            'downloads' => (int) Cache::remember(
                "catalog:app-page:downloads:{$app->id}",
                now()->addMinutes(15),
                fn () => $app->installations()->whereNotNull('delivered_at')->distinct()->count('device_id'),
            ),
            // Only listings with a public page; a web-hidden listing has nothing to share.
            'share_url' => $app->isPubliclyListed() ? $app->seoUrl() : null,
            'versions' => VersionResource::collection(
                $app->versions()->orderByDesc('released_at')->orderByDesc('id')->limit(5)->get()
            )->resolve($request),
            'related' => $summaries($related),
            'more_from_publisher' => $summaries($fromPublisher),
        ];
    }

    /**
     * Prepare the complete build while the customer reads the app page.
     */
    private function warmBuild(CatalogApp $app, Request $request): void
    {
        $artifact = $app->publishedArtifact;
        $device = $artifact === null ? null : app(CurrentDevice::class)->resolve($request);
        if ($device === null || $device->latestRegistration?->status !== DeviceRegistrationStatus::Eligible) {
            return;
        }

        app(BuildWarmup::class)->forApp($device, $app);
    }

    public function versions(Request $request, string $app): JsonResponse
    {
        $versions = $this->findVisible($app)
            ->versions()
            ->orderByDesc('released_at')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::ok(VersionResource::collection($versions)->resolve($request));
    }

    private function findVisible(string $publicId): CatalogApp
    {
        return CatalogApp::query()->visibleToCustomers()->where('public_id', strtolower($publicId))->firstOrFail();
    }
}
