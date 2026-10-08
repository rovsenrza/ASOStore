<?php

namespace App\Services\Seo;

/** One server-rendered public page: what goes into <head>, and the <main> content. */
final readonly class SeoPage
{
    /**
     * @param  list<array<string, mixed>>  $schema  schema.org nodes, published as one JSON-LD @graph
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public string $main,
        public array $schema = [],
        public ?string $image = null,
        public string $robots = 'index,follow,max-image-preview:large',
        public string $ogType = 'website',
    ) {}
}
