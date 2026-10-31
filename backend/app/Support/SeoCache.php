<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Rendered public pages (catalog, categories, apps, blog) are cached for hours. A page shows other
 * apps too (related apps, category lists, blog mentions), so a catalog change drops them all at
 * once: every key carries a generation number, and flushing bumps it. Old entries expire unused.
 */
class SeoCache
{
    private const GENERATION = 'seo:generation';

    public static function key(string $page): string
    {
        return 'seo:page:'.Cache::get(self::GENERATION, 0).':'.$page;
    }

    public static function flush(): void
    {
        if (! Cache::add(self::GENERATION, 1)) {
            Cache::increment(self::GENERATION);
        }
        Cache::forget('seo:sitemap');
    }
}
