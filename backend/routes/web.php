<?php

use App\Http\Controllers\Web\BlogController;
use App\Http\Controllers\Web\CatalogPageController;
use App\Http\Controllers\Web\SitemapController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
| Public catalog pages for search engines and visitors: no session or cookies, so responses stay
| cacheable and identical for every visitor.
*/
Route::withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class, AddQueuedCookiesToResponse::class, EncryptCookies::class])
    ->group(function () {
        Route::get('/apps', [CatalogPageController::class, 'index'])->name('seo.apps');
        Route::get('/apps/{slug}', [CatalogPageController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('seo.app');
        Route::get('/categories/{slug}', [CatalogPageController::class, 'category'])->where('slug', '[a-z0-9-]+')->name('seo.category');
        Route::get('/blog', [BlogController::class, 'index'])->name('seo.blog');
        Route::get('/blog/{slug}', [BlogController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('seo.blog-post');
        Route::get('/sitemap.xml', SitemapController::class)->name('seo.sitemap');
        // IndexNow proves the site owns its key with a text file named after it.
        Route::get('/{key}.txt', function (string $key) {
            abort_unless($key === (string) config('seo.indexnow.key') && $key !== '', 404);

            return response($key, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
        })->where('key', '[A-Za-z0-9-]{8,128}')->name('seo.indexnow-key');
    });

/*
| The customer portal and admin panel are static sites copied into public/ by
| scripts/build-public.sh (IMPLEMENTATION_PLAN D1). On Apache, DirectoryIndex
| serves them directly; this route covers `php artisan serve`, which always
| sends "/" to Laravel.
*/

Route::get('/', function () {
    $portal = public_path('index.html');

    abort_unless(is_file($portal), 404, 'Portal not published. Run scripts/build-public.sh.');

    return response()->file($portal, ['Content-Type' => 'text/html; charset=UTF-8']);
});
