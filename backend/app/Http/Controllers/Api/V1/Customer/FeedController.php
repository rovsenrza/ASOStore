<?php

namespace App\Http\Controllers\Api\V1\Customer;

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
 * "most downloaded" counts the devices that received an app, "trending" those
 * of the last seven days. A section with no data is left out.
 *
 * Every list is ranked on its own: with a catalog of hundreds of apps, hiding
 * an app from one list because another already shows it emptied whole lists
 * («Новые» with one app). Only "trending" leaves out the leaders of "most
 * downloaded", so it shows what is rising instead of the same first rows.
 * «Русские приложения» gets its own list on Home and the Apps tab.
 */
class FeedController extends Controller
{
    private const TITLES = [
        'all' => ['most_downloaded' => 'Самые загружаемые', 'trending' => 'Тенденции'],
        'games' => ['most_downloaded' => 'Самые скачиваемые игры', 'trending' => 'Трендовые игры'],
        'apps' => ['most_downloaded' => 'Самые загружаемые приложения', 'trending' => 'Популярные приложения'],
    ];

    /** How many of the most downloaded apps "trending" leaves out. */
    private const TRENDING_SKIPS_LEADERS = 10;

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

        $mostDownloaded = $this->byDownloads($kind, $limit);
        $leaders = $mostDownloaded->take(self::TRENDING_SKIPS_LEADERS)->pluck('id');
        $trending = $this->byDownloads($kind, $limit, now()->subDays(7), $leaders->all());
        $russianCategory = $kind === 'games' ? null : AppCategory::query()->where('slug', config('storefront.catalog.feed_russian_category'))->first();
        $russian = $russianCategory === null ? collect() : $this->apps($kind)
            ->where('category_id', $russianCategory->id)
            ->withDownloads()
            ->orderByDesc('downloads')
            ->orderBy('name')
            ->limit($limit)
            ->get();

        $recentlyUpdated = $this->apps($kind)
            ->withMax('versions', 'released_at')
            ->whereHas('versions')
            ->orderByDesc('versions_max_released_at')
            ->orderByDesc('created_at')
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
            $this->appSection('russian', 'carousel', $russianCategory->title ?? '', $russian, $request),
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
     * Apps that reached at least one device (since a moment), most devices first.
     *
     * @param  list<int>  $except
     * @return Collection<int, CatalogApp>
     */
    private function byDownloads(string $kind, int $limit, ?\DateTimeInterface $since = null, array $except = []): Collection
    {
        return $this->apps($kind)
            ->whereKeyNot($except)
            ->withDownloads($since)
            ->whereHas('installations', fn ($installations) => $installations->whereNotNull('delivered_at')
                ->when($since !== null, fn ($query) => $query->where('delivered_at', '>=', $since)))
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
