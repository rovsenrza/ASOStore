<?php

namespace App\Services\Seo;

use App\Models\AppCategory;
use App\Models\CatalogApp;
use Illuminate\Support\Carbon;

/** Every indexable page of the site, for the sitemap and IndexNow. */
class PublicUrls
{
    /** Website pages built by Vite: path => [file in public/, priority, change frequency]. */
    public const STATIC_PAGES = [
        '/' => ['index.html', '1.0', 'weekly'],
        '/apps' => [null, '0.9', 'daily'],
        '/buy.html' => ['buy.html', '0.9', 'monthly'],
        '/install.html' => ['install.html', '0.7', 'monthly'],
        '/activate.html' => ['activate.html', '0.6', 'monthly'],
        '/support.html' => ['support.html', '0.6', 'monthly'],
    ];

    /**
     * @return list<array{loc: string, lastmod: ?Carbon, priority: string, changefreq: string, image: ?string}>
     */
    public function all(?Carbon $changedSince = null): array
    {
        $base = config('seo.base_url');
        $apps = CatalogApp::query()->publiclyListed()->with(['category', 'publishedArtifact'])->orderBy('id')->get();
        $updated = fn (CatalogApp $app) => collect([$app->updated_at, $app->publishedArtifact?->updated_at])->filter()->max();
        $newest = $apps->map($updated)->filter()->max();

        $urls = [];
        foreach (self::STATIC_PAGES as $path => [$file, $priority, $frequency]) {
            $modified = $file !== null && is_file(public_path($file)) ? Carbon::createFromTimestamp(filemtime(public_path($file))) : $newest;
            $urls[] = ['loc' => $base.$path, 'lastmod' => $modified, 'priority' => $priority, 'changefreq' => $frequency, 'image' => null];
        }
        foreach ($apps->groupBy('category_id') as $list) {
            /** @var AppCategory $category */
            $category = $list->first()->category;
            $urls[] = ['loc' => $base.'/categories/'.$category->slug, 'lastmod' => $list->map($updated)->filter()->max(),
                'priority' => '0.8', 'changefreq' => 'weekly', 'image' => null];
        }
        foreach ($apps as $app) {
            $urls[] = ['loc' => $app->seoUrl(), 'lastmod' => $updated($app), 'priority' => '0.7', 'changefreq' => 'weekly', 'image' => $app->iconUrl()];
        }
        $posts = app(Blog::class)->all();
        if ($posts->isNotEmpty()) {
            $urls[] = ['loc' => $base.'/blog', 'lastmod' => $posts->max('updated'), 'priority' => '0.8', 'changefreq' => 'weekly', 'image' => null];
        }
        foreach ($posts as $post) {
            $urls[] = ['loc' => $post->url(), 'lastmod' => $post->updated, 'priority' => '0.8', 'changefreq' => 'monthly', 'image' => $base.$post->image];
        }

        if ($changedSince === null) {
            return $urls;
        }

        return array_values(array_filter($urls, fn (array $url) => $url['lastmod'] !== null && $url['lastmod']->greaterThanOrEqualTo($changedSince)));
    }
}
