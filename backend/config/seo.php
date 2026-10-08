<?php

return [
    // Canonical origin of public pages, whatever host a request came in on (www, http, the IP).
    'base_url' => rtrim((string) env('SEO_BASE_URL', 'https://ruappstore.com'), '/'),

    // The website page the public catalog pages are rendered into (header, footer, styles).
    'shell_path' => env('SEO_SHELL_PATH', public_path('catalog-shell.html')),

    // IndexNow (Yandex, Bing): tells search engines about new and changed pages at once.
    'indexnow' => [
        'key' => env('SEO_INDEXNOW_KEY'),
        'endpoint' => env('SEO_INDEXNOW_ENDPOINT', 'https://yandex.com/indexnow'),
    ],

    // Rendered pages are cached briefly; catalog changes show up within this time.
    'cache_seconds' => (int) env('SEO_CACHE_SECONDS', 600),
];
