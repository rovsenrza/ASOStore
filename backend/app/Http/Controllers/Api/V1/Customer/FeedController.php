<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppSummaryResource;
use App\Http\Resources\CategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\AppCategory;
use App\Models\CatalogApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Curated sections for the native Today tab (IMPLEMENTATION_PLAN G16).
 */
class FeedController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $limit = config('storefront.catalog.feed_section_limit');

        $featured = CatalogApp::query()->visibleToCustomers()
            ->with(AppSummaryResource::RELATIONS)
            ->whereNotNull('featured_rank')
            ->orderBy('featured_rank')
            ->limit($limit)
            ->get();

        $recentlyUpdated = CatalogApp::query()->visibleToCustomers()
            ->with(AppSummaryResource::RELATIONS)
            ->withMax('versions', 'released_at')
            ->whereHas('versions')
            ->orderByDesc('versions_max_released_at')
            ->limit($limit)
            ->get();

        $categories = AppCategory::query()
            ->withCount(['apps' => fn ($query) => $query->visibleToCustomers()])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->filter(fn (AppCategory $category) => $category->apps_count > 0)
            ->values();

        $sections = array_values(array_filter([
            $this->appSection('featured', 'featured', 'Выбор редакции', $featured, $request),
            $this->appSection('recently_updated', 'carousel', 'Недавно обновлённые', $recentlyUpdated, $request),
            $categories->isEmpty() ? null : [
                'id' => 'categories',
                'kind' => 'categories',
                'title' => 'Категории',
                'categories' => CategoryResource::collection($categories)->resolve($request),
            ],
        ]));

        return ApiResponse::ok(['sections' => $sections]);
    }

    /**
     * @param  Collection<int, CatalogApp>  $apps
     * @return array<string, mixed>|null
     */
    private function appSection(string $id, string $kind, string $title, $apps, Request $request): ?array
    {
        if ($apps->isEmpty()) {
            return null;
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'title' => $title,
            'apps' => AppSummaryResource::collection($apps)->resolve($request),
        ];
    }
}
