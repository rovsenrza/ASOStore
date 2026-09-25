<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppDetailResource;
use App\Http\Resources\AppSummaryResource;
use App\Http\Resources\VersionResource;
use App\Http\Responses\ApiResponse;
use App\Models\CatalogApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:64'],
            'kind' => ['nullable', 'in:apps,games'],
            // featured (default), updated (newest release first), new (newest listing first)
            'sort' => ['nullable', 'in:featured,updated,new'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('storefront.catalog.per_page_max')],
        ]);

        $query = CatalogApp::query()
            ->visibleToCustomers()
            ->with(AppSummaryResource::RELATIONS);

        match ($validated['sort'] ?? 'featured') {
            'updated' => $query->withMax('versions', 'released_at')->orderByDesc('versions_max_released_at')->orderBy('name'),
            'new' => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderByRaw('featured_rank IS NULL, featured_rank')->orderBy('name'),
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

        return ApiResponse::ok((new AppDetailResource($model))->resolve($request));
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
