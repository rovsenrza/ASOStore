<?php

namespace App\Http\Controllers\Web;

use App\Enums\CategoryKind;
use App\Http\Controllers\Controller;
use App\Models\AppCategory;
use App\Models\CatalogApp;
use App\Services\Seo\CategoryCopy;
use App\Services\Seo\PageShell;
use App\Services\Seo\Schema;
use App\Services\Seo\SeoPage;
use App\Services\TelegramStore\StoreSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
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
            $count = $total.' '.self::plural($total, 'приложение', 'приложения', 'приложений');
            $url = Schema::base().'/apps';
            $title = 'Каталог приложений для iPhone без App Store — '.$count.' | Ru App Store';
            $description = 'Скачать на айфон приложения, которых нет в российском App Store: банки, маркетплейсы, соцсети, игры и сервисы — '.$count.'. Установка через Ru App Store.';

            return new SeoPage(
                title: $title,
                description: $description,
                canonical: $url,
                main: view('seo.catalog', ['categories' => $categories, 'apps' => $apps, 'total' => $total, 'count' => $count, 'perCategory' => self::OVERVIEW_PER_CATEGORY])->render(),
                schema: [
                    Schema::organization(),
                    Schema::website(),
                    Schema::webPage('CollectionPage', $url, $title, $description, extra: [
                        'about' => 'Приложения для iPhone, которых нет в российском App Store',
                        'mainEntity' => [
                            '@type' => 'ItemList',
                            'name' => 'Категории каталога',
                            'numberOfItems' => $categories->count(),
                            'itemListElement' => $categories->values()->map(fn (AppCategory $category, int $i) => [
                                '@type' => 'ListItem', 'position' => $i + 1, 'name' => CategoryCopy::heading($category),
                                'url' => Schema::base().'/categories/'.$category->slug,
                            ])->all(),
                        ],
                    ]),
                    Schema::breadcrumbs($url, [['Каталог', $url]]),
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
            $url = Schema::base().'/categories/'.$category->slug;
            $count = $apps->count().' '.self::plural($apps->count(), 'приложение', 'приложения', 'приложений');
            $heading = CategoryCopy::heading($category);
            $title = $heading.' — скачать без App Store | Ru App Store';
            $description = Str::limit($heading.': '.$count.' — '.$apps->take(4)->pluck('name')->implode(', ')
                .' и другие. Скачайте на айфон через Ru App Store без компьютера и джейлбрейка.', 158);

            return new SeoPage(
                title: $title,
                description: $description,
                canonical: $url,
                main: view('seo.category', [
                    'category' => $category, 'apps' => $apps, 'count' => $count, 'categories' => $this->categories(),
                    'heading' => $heading, 'intro' => CategoryCopy::intro($category, $count),
                ])->render(),
                schema: [
                    Schema::organization(),
                    Schema::website(),
                    Schema::webPage('CollectionPage', $url, $title, $description, $apps->first()?->iconUrl(), $this->lastModified($apps), [
                        'mainEntity' => [
                            '@type' => 'ItemList',
                            'name' => $heading,
                            'numberOfItems' => $apps->count(),
                            'itemListElement' => $apps->values()->map(fn (CatalogApp $app, int $i) => [
                                '@type' => 'ListItem', 'position' => $i + 1, 'url' => $app->seoUrl(), 'name' => $app->name,
                            ])->all(),
                        ],
                    ]),
                    Schema::breadcrumbs($url, [['Каталог', Schema::base().'/apps'], [$category->title, $url]]),
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
        $base = Schema::base();
        $name = $app->name;
        $url = $app->seoUrl();
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
        $modified = $this->lastModified(collect([$app]));
        $title = self::appTitle($name);
        $description = Str::limit($name.' нет в App Store? Скачайте '.$name.' на айфон через Ru App Store: без компьютера и джейлбрейка'
            .($facts['min_ios'] ? ', iOS '.$facts['min_ios'].'+' : '').', обновления в каталоге.', 158);
        $image = $app->bannerUrl() ?? $app->iconUrl();

        $software = array_filter([
            '@type' => 'SoftwareApplication',
            '@id' => $url.'#app',
            'name' => $name,
            'url' => $url,
            'description' => self::summary($app),
            'operatingSystem' => $facts['min_ios'] ? 'iOS '.$facts['min_ios'].' или новее' : 'iOS',
            'applicationCategory' => self::schemaCategory($app->category),
            'applicationSubCategory' => $app->category->title,
            'availableOnDevice' => 'iPhone, iPad',
            'softwareVersion' => $facts['version'],
            'fileSize' => $facts['size'] ? self::megabytes($facts['size']).' MB' : null,
            'contentRating' => $app->age_rating,
            'datePublished' => $app->created_at?->toAtomString(),
            'dateModified' => $modified?->toAtomString(),
            'releaseNotes' => $app->latestVersion?->release_notes ? Str::limit(trim($app->latestVersion->release_notes), 500) : null,
            'featureList' => self::features($app->description) ?: null,
            'keywords' => "скачать {$name} на iPhone, {$name} на айфон, установить {$name} без App Store, {$name} для iOS",
            'image' => $app->iconUrl(),
            'screenshot' => $app->screenshots->take(6)->map->url()->values()->all() ?: null,
            'author' => $app->publisher ? ['@type' => 'Organization', 'name' => $app->publisher->name] : null,
            'offers' => $price ? [
                '@type' => 'Offer', 'price' => (string) $price, 'priceCurrency' => 'RUB',
                'description' => 'Доступ к каталогу Ru App Store на месяц', 'url' => $base.'/buy.html',
                'availability' => 'https://schema.org/InStock', 'seller' => ['@id' => $base.'/#org'],
            ] : null,
        ], fn ($value) => $value !== null);

        return new SeoPage(
            title: $title,
            description: $description,
            canonical: $url,
            main: view('seo.app', [
                'app' => $app, 'facts' => $facts, 'price' => $price, 'related' => $related, 'faq' => $faq,
                'paragraphs' => self::paragraphs($app->description), 'categoryUrl' => $categoryUrl,
                'about' => self::paragraphs($app->seo_about),
                'releaseNotes' => self::paragraphs($app->latestVersion?->release_notes),
                'categoryIntro' => CategoryCopy::intro($app->category, 'Приложения'),
            ])->render(),
            schema: [
                Schema::organization(),
                Schema::website(),
                Schema::webPage('ItemPage', $url, $title, $description, $image, $modified, ['mainEntity' => ['@id' => $url.'#app']]),
                Schema::breadcrumbs($url, [['Каталог', $base.'/apps'], [$app->category->title, $categoryUrl], [$name, $url]]),
                $software,
                [
                    '@type' => 'HowTo',
                    '@id' => $url.'#install',
                    'name' => "Как скачать и установить {$name} на iPhone",
                    'description' => "Установка {$name} на iPhone без App Store, компьютера и джейлбрейка.",
                    'tool' => [['@type' => 'HowToTool', 'name' => 'iPhone или iPad'.($facts['min_ios'] ? ' с iOS '.$facts['min_ios'].' или новее' : '')],
                        ['@type' => 'HowToTool', 'name' => 'Браузер Safari']],
                    'step' => Schema::installSteps($name, $price),
                ],
                ['@type' => 'FAQPage', '@id' => $url.'#faq', 'mainEntity' => array_map(fn (array $item) => [
                    '@type' => 'Question', 'name' => $item[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item[1]],
                ], $faq)],
            ],
            image: $image,
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
            ["Как скачать {$name} на айфон, если его нет в App Store?",
                "Через Ru App Store: купите доступ, зарегистрируйте iPhone в Safari, установите приложение Ru App Store и нажмите «Установить» у {$name}. Приложение подписывается для вашего устройства, поэтому App Store не нужен."],
        ];
        if ($facts['min_ios']) {
            $faq[] = ["На каких iPhone работает {$name}?", "Нужен iPhone или iPad с iOS {$facts['min_ios']} или новее."];
        }
        if ($price) {
            $faq[] = ["Сколько стоит установить {$name}?",
                "Отдельно приложение не продаётся. Доступ к Ru App Store стоит от {$price} ₽ в месяц и включает весь каталог, обновления приложений и поддержку."];
        }
        $faq[] = ['Нужен ли компьютер или джейлбрейк?',
            'Нет. Всё делается на самом iPhone: регистрация устройства в Safari и установка из приложения Ru App Store.'];
        $faq[] = ["Как обновлять {$name}?", 'Новые версии появляются в каталоге Ru App Store и ставятся из приложения Ru App Store так же, как первая установка.'];
        $faq[] = ["Безопасно ли устанавливать {$name} не из App Store?",
            "Каждый файл проверяется перед публикацией в каталоге, а {$name} работает в обычной песочнице iOS, как любое другое приложение. Логины, пароли и платёжные данные вы вводите в само приложение — Ru App Store их не получает."];
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

    /** @param  \Illuminate\Support\Collection<int, CatalogApp>  $apps */
    private function lastModified(\Illuminate\Support\Collection $apps): ?Carbon
    {
        return $apps->flatMap(fn (CatalogApp $app) => [$app->updated_at, $app->publishedArtifact?->updated_at])->filter()->max();
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
        $text = $app->subtitle ?: (self::paragraphs($app->seo_about)[0] ?? self::paragraphs($app->description)[0] ?? '');

        return Str::limit(trim($text), 300);
    }

    /** @return list<string> */
    public static function paragraphs(?string $text): array
    {
        $parts = preg_split('/\R\s*\R|\R/u', trim((string) $text)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $part) => $part !== ''));
    }

    /**
     * The bulleted lines of an App Store description («・Переводы…», «- Оплата…»), for featureList.
     *
     * @return list<string>
     */
    public static function features(?string $text): array
    {
        $features = [];
        foreach (self::paragraphs($text) as $line) {
            if (preg_match('/^[・•●▪◦✓✔*\-–—]+\s*(.+)$/u', $line, $match) === 1) {
                $features[] = Str::limit(rtrim($match[1], ' ;.,'), 120);
            }
        }

        return array_slice($features, 0, 8);
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
