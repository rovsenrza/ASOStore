<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\InstallationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppSummaryResource;
use App\Http\Resources\CategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\AppCategory;
use App\Models\CatalogApp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Curated sections for the native Home, Games and Apps tabs (IMPLEMENTATION_PLAN G16).
 *
 * Rankings come from real data only (PRODUCT.md: no fabricated metrics):
 * "most downloaded" counts delivered installations, "trending" counts those
 * of the last seven days. A section with no data is left out.
 */
class FeedController extends Controller
{
    private const TITLES = [
        'all' => ['most_downloaded' => 'Самые загружаемые', 'trending' => 'Тенденции'],
        'games' => ['most_downloaded' => 'Самые скачиваемые игры', 'trending' => 'Трендовые игры'],
        'apps' => ['most_downloaded' => 'Самые загружаемые приложения', 'trending' => 'Популярные приложения'],
    ];

    public function __invoke(Request $request): JsonResponse
    {
        $kind = $request->validate(['kind' => ['nullable', 'in:apps,games']])['kind'] ?? 'all';
        $limit = (int) config('storefront.catalog.feed_section_limit');
        $titles = self::TITLES[$kind];

        $featured = $this->apps($kind)
            ->whereNotNull('featured_rank')
            ->orderBy('featured_rank')
            ->limit($limit)
            ->get();

        $mostDownloaded = $this->byInstallations($kind, $limit);
        $trending = $this->byInstallations($kind, $limit, now()->subDays(7));

        $recentlyUpdated = $this->apps($kind)
            ->withMax('versions', 'released_at')
            ->whereHas('versions')
            ->orderByDesc('versions_max_released_at')
            ->limit($limit)
            ->get();

        $newest = $this->apps($kind)->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();

        $categories = AppCategory::query()
            ->when($kind !== 'all', fn (Builder $query) => $query->where('kind', strtoupper($kind)))
            ->withCount(['apps' => fn ($query) => $query->visibleToCustomers()])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get()
            ->filter(fn (AppCategory $category) => $category->apps_count > 0)
            ->values();

        $sections = array_values(array_filter([
            $this->appSection('featured', 'featured', 'Выбор редакции', $featured, $request),
            $this->appSection('most_downloaded', 'carousel', $titles['most_downloaded'], $mostDownloaded, $request),
            $this->appSection('trending', 'carousel', $titles['trending'], $trending, $request),
            $this->appSection('recently_updated', 'carousel', 'Недавно обновлённые', $recentlyUpdated, $request),
            $this->appSection('new', 'carousel', 'Новые', $newest, $request),
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
     * @return Builder<CatalogApp>
     */
    private function apps(string $kind): Builder
    {
        return CatalogApp::query()->visibleToCustomers()
            ->with(AppSummaryResource::RELATIONS)
            ->when($kind !== 'all', fn (Builder $query) => $query->whereHas('category', fn (Builder $category) => $category->where('kind', strtoupper($kind))));
    }

    /**
     * Apps with at least one delivered installation, most first.
     *
     * @return Collection<int, CatalogApp>
     */
    private function byInstallations(string $kind, int $limit, ?\DateTimeInterface $since = null): Collection
    {
        $scope = fn ($installations) => $installations
            ->where('status', InstallationStatus::Delivered->value)
            ->when($since !== null, fn ($query) => $query->where('delivered_at', '>=', $since));

        return $this->apps($kind)
            ->withCount(['installations as downloads' => $scope])
            ->whereHas('installations', $scope)
            ->orderByDesc('downloads')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  Collection<int, CatalogApp>  $apps
     * @return array<string, mixed>|null
     */
    private function appSection(string $id, string $kind, string $title, Collection $apps, Request $request): ?array
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
