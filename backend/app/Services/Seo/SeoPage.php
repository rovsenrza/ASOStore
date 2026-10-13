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

    /**
     * The JSON-LD document. Built here, not in the Blade view: Blade compiles the text "@context"
     * as its own @context directive, even inside {!! !!}, which corrupted the key on every page.
     */
    public function jsonLd(): string
    {
        return json_encode(['@context' => 'https://schema.org', '@graph' => $this->schema],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    }
}
