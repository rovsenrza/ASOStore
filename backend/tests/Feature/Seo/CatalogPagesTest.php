<?php

use App\Enums\AppVisibility;
use App\Enums\ArtifactStatus;
use App\Http\Middleware\SecurityHeaders;
use App\Models\AppArtifact;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\AppScreenshot;
use App\Models\CatalogApp;
use App\Services\Seo\SeoSlugs;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['seo.base_url' => 'https://ruappstore.com', 'seo.shell_path' => '/nonexistent/shell.html', 'seo.cache_seconds' => 0]);
    $this->finance = AppCategory::factory()->create(['slug' => 'finance', 'title' => 'Финансы']);
    $this->listing = CatalogApp::factory()->create([
        'name' => 'СберБанк Онлайн',
        'subtitle' => 'Банк в телефоне',
        'description' => "Переводы и платежи.\n\nКарты, вклады и кэшбэк.\n・Переводы по номеру телефона;\n- Оплата ЖКХ без комиссии.",
        'category_id' => $this->finance->id,
        'publisher_id' => AppPublisher::factory()->create(['name' => 'Sberbank of Russia'])->id,
        'icon_path' => 'catalog/icons/sber.webp',
        'featured_rank' => 1,
    ]);
    AppArtifact::factory()->for($this->listing, 'app')->create([
        'status' => ArtifactStatus::Published, 'version' => '15.2.0', 'min_ios_version' => '15.0', 'size_bytes' => 300 * 1048576,
    ]);
    AppScreenshot::create(['app_id' => $this->listing->id, 'path' => 'catalog/screens/sber-1.jpg', 'width' => 1290, 'height' => 2796, 'sort_order' => 1]);
});

it('gives a published listing a readable, unique address', function () {
    expect($this->listing->seo_slug)->toBe('sberbank-onlayn')
        ->and(app(SeoSlugs::class)->base('Яндекс Музыка'))->toBe('yandeks-muzyka')
        ->and(app(SeoSlugs::class)->base('Aвито'))->toBe('avito');

    $twin = CatalogApp::factory()->create(['name' => 'СберБанк Онлайн', 'category_id' => $this->finance->id]);
    $draft = CatalogApp::factory()->create(['name' => 'Черновик', 'visibility' => AppVisibility::Draft]);
    expect($twin->seo_slug)->toBe('sberbank-onlayn-2')
        ->and($draft->seo_slug)->toBeNull();

    // The address stays when the listing is renamed.
    $this->listing->update(['name' => 'Сбербанк']);
    expect($this->listing->refresh()->seo_slug)->toBe('sberbank-onlayn');
});

it('serves an indexable app page with its facts, install steps, FAQ and structured data', function () {
    $response = $this->get('/apps/sberbank-onlayn')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    $html = $response->getContent();

    expect($html)
        ->toContain('<title>СберБанк Онлайн для iPhone — скачать без App Store | Ru App Store</title>')
        ->toContain('<link rel="canonical" href="https://ruappstore.com/apps/sberbank-onlayn">')
        ->toContain('<meta name="robots" content="index,follow,max-image-preview:large">')
        ->toContain('СберБанк Онлайн для&nbsp;iPhone</h1>')
        ->toContain('iOS 15.0 или новее')
        ->toContain('300 МБ')
        ->toContain('Sberbank of Russia')
        ->toContain('Карты, вклады и кэшбэк.')
        ->toContain('Как скачать и установить СберБанк Онлайн на&nbsp;iPhone')
        ->toContain('href="https://ruappstore.com/categories/finance"');
    expect($response->headers->get('Set-Cookie'))->toBeNull();

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);
    $graph = collect(json_decode($match[1], true)['@graph']);
    expect($graph->pluck('@type')->all())->toBe(['Organization', 'WebSite', 'ItemPage', 'BreadcrumbList', 'SoftwareApplication', 'HowTo', 'FAQPage']);
    $software = $graph->firstWhere('@type', 'SoftwareApplication');
    expect($software)->toMatchArray(['name' => 'СберБанк Онлайн', 'operatingSystem' => 'iOS 15.0 или новее', 'applicationCategory' => 'FinanceApplication', 'softwareVersion' => '15.2.0'])
        ->and($software['featureList'])->toBe(['Переводы по номеру телефона', 'Оплата ЖКХ без комиссии'])
        ->and($software['offers']['priceCurrency'])->toBe('RUB')
        ->and($graph->firstWhere('@type', 'ItemPage')['mainEntity'])->toBe(['@id' => 'https://ruappstore.com/apps/sberbank-onlayn#app'])
        ->and($graph->firstWhere('@type', 'BreadcrumbList')['@id'])->toBe('https://ruappstore.com/apps/sberbank-onlayn#breadcrumb')
        ->and($graph->firstWhere('@type', 'HowTo')['step'])->toHaveCount(4);
});

it('escapes catalog text it puts into the page', function () {
    $this->listing->update(['subtitle' => '<script>alert(1)</script>']);

    expect($this->get('/apps/sberbank-onlayn')->getContent())
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('sends an app linked by its public id to the readable address', function () {
    $this->get('/apps/'.$this->listing->public_id)->assertStatus(301)->assertRedirect('https://ruappstore.com/apps/sberbank-onlayn');
});

it('has no public page for drafts, private imports or the storefront', function () {
    $hidden = CatalogApp::factory()->create(['name' => 'Скрытое', 'visibility' => AppVisibility::Published]);
    $hidden->update(['visibility' => AppVisibility::Hidden]);
    $import = CatalogApp::factory()->create(['name' => 'Мой импорт', 'imported_by_user_id' => userWithRoles()->id]);
    $storefront = CatalogApp::factory()->create(['name' => 'Ru App Store', 'is_storefront' => true]);

    foreach ([$hidden, $import, $storefront] as $app) {
        $this->get('/apps/'.$app->seo_slug)->assertNotFound();
    }
    $this->get('/apps/does-not-exist')->assertNotFound();
});

it('lists a category and the whole catalog with links to every app', function () {
    $this->get('/categories/finance')->assertOk()
        ->assertSee('<title>Банковские и финансовые приложения для iPhone — скачать без App Store | Ru App Store</title>', false)
        ->assertSee('Мобильные банки, кошельки и инвестиции', false)
        ->assertSee('href="/apps/sberbank-onlayn"', false);
    $this->get('/categories/nothing-here')->assertNotFound();

    $this->get('/apps')->assertOk()
        ->assertSee('Каталог приложений для&nbsp;iPhone', false)
        ->assertSee('href="/categories/finance"', false)
        ->assertSee('href="/apps/sberbank-onlayn"', false);
});

it('publishes every public page in the sitemap, with app icons', function () {
    CatalogApp::factory()->create(['name' => 'Мой импорт', 'imported_by_user_id' => userWithRoles()->id]);

    $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
    $sitemap = simplexml_load_string($xml);
    $locs = [];
    foreach ($sitemap->url as $url) {
        $locs[] = (string) $url->loc;
    }

    expect($locs)->toContain('https://ruappstore.com/', 'https://ruappstore.com/apps', 'https://ruappstore.com/buy.html',
        'https://ruappstore.com/categories/finance', 'https://ruappstore.com/apps/sberbank-onlayn')
        ->and(collect($locs)->filter(fn ($loc) => str_contains($loc, 'moy-import')))->toBeEmpty()
        ->and($xml)->toContain('<image:loc>');
});

it('renders into the website shell, replacing its placeholder head', function () {
    $shell = tempnam(sys_get_temp_dir(), 'shell');
    file_put_contents($shell, '<html><head><!--seo:head--><title>Ru App Store</title><meta name="robots" content="noindex"><!--/seo:head--><link rel="stylesheet" href="/assets/site.css"></head>'
        .'<body><header>Шапка сайта</header><main id="main"><!--seo:main--></main></body></html>');
    config(['seo.shell_path' => $shell]);

    $html = $this->get('/apps/sberbank-onlayn')->assertOk()->getContent();
    unlink($shell);

    expect($html)->toContain('Шапка сайта')->toContain('/assets/site.css')
        ->toContain('<title>СберБанк Онлайн для iPhone')
        ->not->toContain('content="noindex"')
        ->not->toContain('<!--seo:main-->');
});

it('proves the IndexNow key and submits changed pages', function () {
    config(['seo.indexnow.key' => 'a1b2c3d4e5f6a7b8']);
    $this->get('/a1b2c3d4e5f6a7b8.txt')->assertOk()->assertSee('a1b2c3d4e5f6a7b8');
    $this->get('/0000000000000000.txt')->assertNotFound();

    Http::fake(['yandex.com/*' => Http::response('', 200)]);
    $this->artisan('seo:indexnow --all')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://yandex.com/indexnow'
        && $request['host'] === 'ruappstore.com'
        && $request['keyLocation'] === 'https://ruappstore.com/a1b2c3d4e5f6a7b8.txt'
        && in_array('https://ruappstore.com/apps/sberbank-onlayn', $request['urlList'], true));
});

it('lets public pages run Yandex.Metrika while the API keeps the strict policy', function () {
    $page = $this->get('/apps/sberbank-onlayn')->assertOk();
    expect($page->headers->get('Content-Security-Policy'))->toBe(SecurityHeaders::PUBLIC_PAGE_CSP)
        ->toContain('https://mc.yandex.ru')
        ->and($page->headers->has('X-Frame-Options'))->toBeFalse();

    $this->getJson('/api/v1/health')
        ->assertHeader('Content-Security-Policy', SecurityHeaders::CSP)
        ->assertHeader('X-Frame-Options', 'DENY');
});
