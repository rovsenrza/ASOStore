<?php

namespace App\Services\Seo;

use Illuminate\Support\Carbon;

/** One blog article, read from resources/blog/{slug}.md. */
final readonly class BlogPost
{
    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $apps  seo_slugs of catalog apps the article is about
     * @param  list<array{id: string, title: string}>  $toc  the article's H2 sections
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $metaTitle,
        public string $description,
        public Carbon $published,
        public Carbon $updated,
        public string $image,
        public string $ogImage,
        public string $imageAlt,
        public array $keywords,
        public array $apps,
        public string $html,
        public array $toc,
        public int $words,
    ) {}

    public function url(): string
    {
        return config('seo.base_url').'/blog/'.$this->slug;
    }

    public function readingMinutes(): int
    {
        return max(1, (int) round($this->words / 180));
    }
}
