<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CatalogApp;
use App\Services\Seo\Blog;
use App\Services\Seo\BlogPost;
use App\Services\Seo\PageShell;
use App\Services\Seo\Schema;
use App\Services\Seo\SeoPage;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/** The blog: /blog and /blog/{slug}, rendered into the website shell like the catalog pages. */
class BlogController extends Controller
{
    public function __construct(private readonly PageShell $shell, private readonly Blog $blog) {}

    public function index(): Response
    {
        return $this->cached('blog', function () {
            $posts = $this->blog->all();
            $url = Schema::base().'/blog';
            $title = 'Блог Ru App Store — как установить приложения на iPhone без App Store';
            $description = 'Инструкции и ответы: как скачать на айфон СберБанк Онлайн, Т-Банк и другие приложения, которых нет в App Store, и как делать это безопасно.';

            return new SeoPage(
                title: $title,
                description: $description,
                canonical: $url,
                main: view('seo.blog', ['posts' => $posts])->render(),
                schema: [
                    Schema::organization(),
                    Schema::website(),
                    Schema::webPage('CollectionPage', $url, $title, $description, modified: $posts->max('updated'), extra: ['mainEntity' => ['@id' => $url.'#blog']]),
                    Schema::breadcrumbs($url, [['Блог', $url]]),
                    [
                        '@type' => 'Blog', '@id' => $url.'#blog', 'name' => 'Блог Ru App Store', 'url' => $url, 'inLanguage' => 'ru-RU',
                        'publisher' => ['@id' => Schema::base().'/#org'],
                        'blogPost' => $posts->map(fn (BlogPost $post) => [
                            '@type' => 'BlogPosting', '@id' => $post->url().'#article', 'headline' => $post->title, 'url' => $post->url(),
                            'datePublished' => $post->published->toAtomString(), 'image' => Schema::base().$post->image,
                        ])->all(),
                    ],
                ],
            );
        });
    }

    public function show(string $slug): Response
    {
        $post = $this->blog->find($slug);
        abort_if($post === null, 404);

        return $this->cached('blog/'.$slug, function () use ($post) {
            $base = Schema::base();
            $url = $post->url();
            $apps = CatalogApp::query()->publiclyListed()->whereIn('seo_slug', $post->apps)->get()
                ->sortBy(fn (CatalogApp $app) => array_search($app->seo_slug, $post->apps, true))->values();
            $image = $base.$post->image;

            return new SeoPage(
                title: $post->metaTitle,
                description: $post->description,
                canonical: $url,
                main: view('seo.blog-post', [
                    'post' => $post, 'apps' => $apps,
                    'others' => $this->blog->all()->reject(fn (BlogPost $other) => $other->slug === $post->slug)->take(3),
                ])->render(),
                schema: [
                    Schema::organization(),
                    Schema::website(),
                    Schema::webPage('WebPage', $url, $post->metaTitle, $post->description, $image, $post->updated, ['mainEntity' => ['@id' => $url.'#article']]),
                    Schema::breadcrumbs($url, [['Блог', $base.'/blog'], [$post->title, $url]]),
                    array_filter([
                        '@type' => 'BlogPosting',
                        '@id' => $url.'#article',
                        'headline' => $post->title,
                        'description' => $post->description,
                        'image' => ['@type' => 'ImageObject', 'url' => $image, 'width' => 1200, 'height' => 630, 'caption' => $post->imageAlt],
                        'datePublished' => $post->published->toAtomString(),
                        'dateModified' => $post->updated->toAtomString(),
                        'author' => ['@type' => 'Organization', 'name' => 'Редакция Ru App Store', 'url' => $base.'/blog'],
                        'publisher' => ['@id' => $base.'/#org'],
                        'mainEntityOfPage' => ['@id' => $url],
                        'inLanguage' => 'ru-RU',
                        'articleSection' => 'Инструкции',
                        'keywords' => $post->keywords !== [] ? implode(', ', $post->keywords) : null,
                        'wordCount' => $post->words,
                        'timeRequired' => 'PT'.$post->readingMinutes().'M',
                        'about' => $apps->map(fn (CatalogApp $app) => ['@type' => 'SoftwareApplication', '@id' => $app->seoUrl().'#app', 'name' => $app->name, 'url' => $app->seoUrl()])->all() ?: null,
                    ], fn ($value) => $value !== null),
                ],
                image: $base.$post->ogImage,
                ogType: 'article',
            );
        });
    }

    /** @param  callable(): SeoPage  $page */
    private function cached(string $key, callable $page): Response
    {
        $html = Cache::remember('seo:page:'.$key, config('seo.cache_seconds'), fn () => $this->shell->render($page()));

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'public, max-age=300']);
    }
}
