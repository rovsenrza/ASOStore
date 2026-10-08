<?php

namespace App\Http\Controllers\Web;

use App\Enums\CategoryKind;
use App\Http\Controllers\Controller;
use App\Models\AppCategory;
use App\Models\CatalogApp;
use App\Services\Seo\PageShell;
use App\Services\Seo\SeoPage;
use App\Services\TelegramStore\StoreSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Public, indexable catalog pages: every published app (/apps/{seo_slug}), every category
 * (/categories/{slug}) and the catalog overview (/apps). Server-rendered from the database so
 * search engines see the full content without running scripts.
 */
class CatalogPageController extends Controller
{
    /** Apps per category on the overview; the category page lists all of them. */
    private const OVERVIEW_PER_CATEGORY = 12;

    private const RELATED = 12;

    public function __construct(private readonly PageShell $shell) {}

    public function index(): Response
    {
        return $this->cached('apps', function () {
            $categories = $this->categories();
            $apps = $this->publicApps()->get()->groupBy('category_id');
            $total = $apps->sum(fn (Collection $list) => $list->count());
            $base = config('seo.base_url');

            return new SeoPage(
                title: 'Каталог приложений для iPhone без App Store — '.$total.' '.self::plural($total, 'приложение', 'приложения', 'приложений').' | Ru App Store',
                description: 'Банки, маркетплейсы, соцсети, игры и сервисы, которых нет в российском App Store: '.$total.' '.self::plural($total, 'приложение', 'приложения', 'приложений').' для установки на iPhone через Ru App Store.',
                canonical: $base.'/apps',
                main: view('seo.catalog', ['categories' => $categories, 'apps' => $apps, 'total' => $total, 'perCategory' => self::OVERVIEW_PER_CATEGORY])->render(),
                schema: [
                    $this->breadcrumbs([['Каталог', $base.'/apps']]),
                    ['@type' => 'CollectionPage', '@id' => $base.'/apps', 'name' => 'Каталог приложений Ru App Store', 'url' => $base.'/apps', 'inLanguage' => 'ru'],
                ],
            );
        });
    }

    public function category(string $slug): Response
    {
        $category = AppCategory::query()->where('slug', $slug)->first();
        abort_if($category === null || $category->slug === 'imported', 404);

        return $this->cached('categories/'.$slug, function () use ($category) {
            $apps = $this->publicApps()->where('category_id', $category->id)->get();
            abort_if($apps->isEmpty(), 404);
            $base = config('seo.base_url');
            $url = $base.'/categories/'.$category->slug;
            $count = $apps->count().' '.self::plural($apps->count(), 'приложение', 'приложения', 'приложений');

            return new SeoPage(
                title: $category->title.' для iPhone — '.$count.' без App Store | Ru App Store',
                description: Str::limit($category->title.': '.$count.' для iPhone, которых нет в российском App Store, — '.$apps->take(4)->pluck('name')->implode(', ').' и другие. Установка через Ru App Store без компьютера.', 158),
                canonical: $url,
                main: view('seo.category', ['category' => $category, 'apps' => $apps, 'count' => $count, 'categories' => $this->categories()])->render(),
                schema: [
                    $this->breadcrumbs([['Каталог', $base.'/apps'], [$category->title, $url]]),
                    [
                        '@type' => 'CollectionPage', '@id' => $url, 'name' => $category->title.' для iPhone', 'url' => $url, 'inLanguage' => 'ru',
                        'mainEntity' => [
                            '@type' => 'ItemList',
                            'numberOfItems' => $apps->count(),
                            'itemListElement' => $apps->values()->map(fn (CatalogApp $app, int $i) => [
                                '@type' => 'ListItem', 'position' => $i + 1, 'url' => $app->seoUrl(), 'name' => $app->name,
                            ])->all(),
                        ],
                    ],
                ],
            );
        });
    }

    public function show(string $slug): Response|RedirectResponse
    {
        $app = $this->publicApps()->where('seo_slug', $slug)->first();
        if ($app === null) {
            // The homepage links apps by their public id; send those to the readable address.
            $byId = preg_match('/^[0-9a-z]{26}$/', $slug) === 1 ? $this->publicApps()->where('public_id', $slug)->first() : null;
            abort_if($byId === null, 404);

            return redirect()->to($byId->seoUrl(), 301);
        }

        return $this->cached('apps/'.$slug, fn () => $this->appPage($app));
    }

    private function appPage(CatalogApp $app): SeoPage
    {
        $app->load(['category', 'publisher', 'latestVersion', 'publishedArtifact', 'screenshots']);
        $base = config('seo.base_url');
        $name = $app->name;
        $artifact = $app->publishedArtifact;
        $facts = [
            'version' => $artifact?->version ?? $app->latestVersion?->version,
            'min_ios' => $artifact?->min_ios_version ?? $app->latestVersion?->min_ios_version,
            'size' => $artifact?->size_bytes,
            'updated' => $app->latestVersion?->released_at ?? $artifact?->created_at,
        ];
        $price = $this->monthlyPrice();
        $related = $this->publicApps()->where('category_id', $app->category_id)->whereKeyNot($app->id)->limit(self::RELATED)->get();
        $faq = $this->faq($app, $facts, $price);
        $categoryUrl = $base.'/categories/'.$app->category->slug;
        $description = self::summary($app);

        $software = array_filter([
            '@type' => 'SoftwareApplication',
            '@id' => $app->seoUrl().'#app',
            'name' => $name,
            'url' => $app->seoUrl(),
            'description' => $description,
            'operatingSystem' => $facts['min_ios'] ? 'iOS '.$facts['min_ios'].'+' : 'iOS',
            'applicationCategory' => self::schemaCategory($app->category),
            'softwareVersion' => $facts['version'],
            'fileSize' => $facts['size'] ? self::megabytes($facts['size']).' MB' : null,
            'image' => $app->iconUrl(),
            'screenshot' => $app->screenshots->take(6)->map->url()->values()->all() ?: null,
            'author' => $app->publisher ? ['@type' => 'Organization', 'name' => $app->publisher->name] : null,
            'offers' => $price ? [
                '@type' => 'Offer', 'price' => (string) $price, 'priceCurrency' => 'RUB',
                'url' => $base.'/buy.html', 'availability' => 'https://schema.org/InStock',
            ] : null,
        ], fn ($value) => $value !== null);

        return new SeoPage(
            title: self::appTitle($name),
            description: Str::limit($name.' нет в App Store? Установите '.$name.' на iPhone через Ru App Store'
                .($facts['min_ios'] ? ' (iOS '.$facts['min_ios'].'+)' : '').': без компьютера и джейлбрейка, обновления в каталоге.', 158),
            canonical: $app->seoUrl(),
            main: view('seo.app', [
                'app' => $app, 'facts' => $facts, 'price' => $price, 'related' => $related, 'faq' => $faq,
                'paragraphs' => self::paragraphs($app->description), 'categoryUrl' => $categoryUrl,
            ])->render(),
            schema: [
                $this->breadcrumbs([['Каталог', $base.'/apps'], [$app->category->title, $categoryUrl], [$name, $app->seoUrl()]]),
                $software,
                ['@type' => 'FAQPage', 'mainEntity' => array_map(fn (array $item) => [
                    '@type' => 'Question', 'name' => $item[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]],
                ], $faq)],
            ],
            image: $app->bannerUrl() ?? $app->iconUrl(),
        );
    }

    /**
     * @param  array{version: ?string, min_ios: ?string, size: ?int, updated: mixed}  $facts
     * @return list<array{0: string, 1: string}>
     */
    private function faq(CatalogApp $app, array $facts, ?int $price): array
    {
        $name = $app->name;
        $faq = [
            ["Можно ли скачать {$name} на iPhone, если его нет в App Store?",
                "Да. Ru App Store устанавливает {$name} на iPhone напрямую, без App Store: приложение подписывается для вашего устройства. Компьютер и джейлбрейк не нужны."],
        ];
        if ($facts['min_ios']) {
            $faq[] = ["На каких iPhone работает {$name}?", "Нужен iPhone или iPad с iOS {$facts['min_ios']} или новее."];
        }
        if ($price) {
            $faq[] = ["Сколько стоит установить {$name}?",
                "Отдельно приложение не продаётся. Доступ к Ru App Store стоит от {$price} ₽ в месяц и включает весь каталог, обновления приложений и поддержку."];
        }
        $faq[] = ["Как обновлять {$name}?", 'Новые версии появляются в каталоге Ru App Store и ставятся из приложения Ru App Store так же, как первая установка.'];
        $faq[] = ["{$name} — официальное приложение?", ($app->publisher ? "{$name} разработано {$app->publisher->name}. " : '')
            .'Ru App Store не связан с разработчиком: мы помогаем установить приложение на iPhone, когда его нет в App Store вашего региона.'];

        return $faq;
    }

    /** @return Builder<CatalogApp> */
    private function publicApps(): Builder
    {
        return CatalogApp::query()
            ->publiclyListed()
            ->orderByRaw('featured_rank is null, featured_rank')
            ->orderBy('name');
    }

    /** @return Collection<int, AppCategory> */
    private function categories(): Collection
    {
        return AppCategory::query()
            ->where('slug', '!=', 'imported')
            ->withCount(['apps' => fn ($query) => $query->publiclyListed()])
            ->get()
            ->filter(fn (AppCategory $category) => $category->apps_count > 0)
            ->sortByDesc('apps_count')
            ->values();
    }

    /**
     * @param  list<array{0: string, 1: string}>  $trail
     * @return array<string, mixed>
     */
    private function breadcrumbs(array $trail): array
    {
        $items = [['Ru App Store', config('seo.base_url').'/'], ...$trail];

        return ['@type' => 'BreadcrumbList', 'itemListElement' => array_map(fn (array $item, int $i) => [
            '@type' => 'ListItem', 'position' => $i + 1, 'name' => $item[0], 'item' => $item[1],
        ], $items, array_keys($items))];
    }

    private function monthlyPrice(): ?int
    {
        $price = app(StoreSettings::class)->plan('month1')['price'] ?? null;

        return is_numeric($price) ? (int) $price : null;
    }

    /** @param  callable(): SeoPage  $page */
    private function cached(string $key, callable $page): Response
    {
        $html = Cache::remember('seo:page:'.$key, config('seo.cache_seconds'), fn () => $this->shell->render($page()));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    public static function appTitle(string $name): string
    {
        return Str::limit($name, 40, '…').' для iPhone — скачать без App Store | Ru App Store';
    }

    public static function summary(CatalogApp $app): string
    {
        $text = $app->subtitle ?: (self::paragraphs($app->description)[0] ?? '');

        return Str::limit(trim($text), 300);
    }

    /** @return list<string> */
    public static function paragraphs(?string $text): array
    {
        $parts = preg_split('/\R\s*\R|\R/u', trim((string) $text)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    public static function megabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, $bytes >= 104857600 ? 0 : 1, ',', '');
    }

    public static function plural(int $n, string $one, string $few, string $many): string
    {
        $tens = $n % 100;
        $ones = $n % 10;

        return ($tens >= 11 && $tens <= 14) ? $many : ($ones === 1 ? $one : (($ones >= 2 && $ones <= 4) ? $few : $many));
    }

    private static function schemaCategory(AppCategory $category): string
    {
        if ($category->kind === CategoryKind::Games) {
            return 'GameApplication';
        }

        return match ($category->slug) {
            'finance' => 'FinanceApplication',
            'social' => 'SocialNetworkingApplication',
            'music', 'photo-video' => 'MultimediaApplication',
            'business', 'productivity' => 'BusinessApplication',
            'travel' => 'TravelApplication',
            'education' => 'EducationalApplication',
            'entertainment' => 'EntertainmentApplication',
            'health' => 'HealthApplication',
            default => 'UtilitiesApplication',
        };
    }
}
