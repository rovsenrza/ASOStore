<?php

namespace App\Services\Seo;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The blog: Markdown articles in resources/blog/, each with a front matter block of
 * `key: value` lines (title, meta_title, description, published, updated, image, og_image,
 * image_alt, keywords, apps). Adding an article is adding a file and its cover image.
 */
class Blog
{
    /** @var Collection<int, BlogPost>|null */
    private ?Collection $posts = null;

    /** @return Collection<int, BlogPost> newest first */
    public function all(): Collection
    {
        return $this->posts ??= collect(glob($this->directory().'/*.md') ?: [])
            ->map(fn (string $path) => $this->parse($path))
            ->sortByDesc(fn (BlogPost $post) => $post->published->getTimestamp().$post->slug)
            ->values();
    }

    public function find(string $slug): ?BlogPost
    {
        return $this->all()->first(fn (BlogPost $post) => $post->slug === $slug);
    }

    private function directory(): string
    {
        return (string) config('seo.blog_path', resource_path('blog'));
    }

    // Every pattern here needs /u: without it \R also matches byte 0x85, which is the second byte
    // of Cyrillic «х», and splits Russian text in the middle of a letter.
    private function parse(string $path): BlogPost
    {
        $source = (string) file_get_contents($path);
        if (preg_match('/\A---\R(.*?)\R---\R(.*)\z/su', $source, $parts) !== 1) {
            throw new RuntimeException("Blog article without front matter: {$path}");
        }
        $meta = [];
        foreach (preg_split('/\R/u', $parts[1]) ?: [] as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/u', $line, $pair) === 1) {
                $meta[$pair[1]] = trim($pair[2]);
            }
        }
        foreach (['title', 'description', 'published', 'image'] as $required) {
            if (($meta[$required] ?? '') === '') {
                throw new RuntimeException("Blog article {$path} has no {$required}.");
            }
        }

        $list = fn (string $key) => array_values(array_filter(array_map('trim', explode(',', $meta[$key] ?? '')), fn (string $item) => $item !== ''));
        [$html, $toc] = $this->render($parts[2]);
        $published = Carbon::parse($meta['published'], 'Europe/Moscow');

        return new BlogPost(
            slug: basename($path, '.md'),
            title: $meta['title'],
            metaTitle: $meta['meta_title'] ?? $meta['title'],
            description: $meta['description'],
            published: $published,
            updated: isset($meta['updated']) ? Carbon::parse($meta['updated'], 'Europe/Moscow') : $published,
            image: $meta['image'],
            ogImage: $meta['og_image'] ?? $meta['image'],
            imageAlt: $meta['image_alt'] ?? $meta['title'],
            keywords: $list('keywords'),
            apps: $list('apps'),
            html: $html,
            toc: $toc,
            words: (int) preg_match_all('/[\p{L}\p{N}]+/u', strip_tags($html)),
        );
    }

    /**
     * Markdown to HTML (raw HTML in the source is dropped), with an id on every H2 for the
     * table of contents and lazy loading on images.
     *
     * @return array{0: string, 1: list<array{id: string, title: string}>}
     */
    private function render(string $markdown): array
    {
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $toc = [];
        $slugs = app(SeoSlugs::class);
        $html = (string) preg_replace_callback('/<h2>(.*?)<\/h2>/su', function (array $match) use (&$toc, $slugs) {
            $title = html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $id = $slugs->base($title);
            $toc[] = ['id' => $id, 'title' => $title];

            return '<h2 id="'.e($id).'">'.$match[1].'</h2>';
        }, $html);
        $html = str_replace('<img ', '<img loading="lazy" decoding="async" ', $html);

        return [$html, $toc];
    }
}
