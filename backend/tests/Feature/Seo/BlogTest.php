<?php

use App\Models\AppCategory;
use App\Models\CatalogApp;
use App\Services\Seo\Blog;

beforeEach(function () {
    config(['seo.base_url' => 'https://ruappstore.com', 'seo.shell_path' => '/nonexistent/shell.html', 'seo.cache_seconds' => 0]);
    $this->dir = sys_get_temp_dir().'/blog-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    config(['seo.blog_path' => $this->dir]);
    file_put_contents($this->dir.'/kak-skachat-sber.md', <<<'MD'
---
title: Как скачать Сбер на iPhone
meta_title: Как скачать Сбер на iPhone — инструкция
description: Пошаговая инструкция для айфона.
published: 2026-10-08
updated: 2026-10-09
image: /assets/blog/sber.webp
og_image: /assets/blog/sber.jpg
image_alt: Иконка Сбера
keywords: скачать сбер на айфон, сбер для iphone
apps: sberbank-onlayn
---
Вступление со [ссылкой](/apps/sberbank-onlayn).

## Почему его нет в App Store

Текст. <script>alert(1)</script>

## Способы установки

Текст.

## Частые вопросы

Текст.
MD);
    file_put_contents($this->dir.'/starshaya-statya.md', "---\ntitle: Старая статья\ndescription: Описание.\npublished: 2026-09-01\nimage: /assets/blog/old.webp\n---\nТекст.\n");
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('lists articles newest first', function () {
    $html = $this->get('/blog')->assertOk()->getContent();

    expect($html)->toContain('<title>Блог Ru App Store')
        ->and(strpos($html, 'Как скачать Сбер на iPhone'))->toBeLessThan(strpos($html, 'Старая статья'))
        ->and($html)->toContain('href="/blog/kak-skachat-sber"');
});

it('serves an article with its table of contents, related apps and BlogPosting data', function () {
    $finance = AppCategory::factory()->create(['slug' => 'finance']);
    CatalogApp::factory()->create(['name' => 'СберБанк Онлайн', 'category_id' => $finance->id]);

    $html = $this->get('/blog/kak-skachat-sber')->assertOk()->getContent();

    expect($html)
        ->toContain('<title>Как скачать Сбер на iPhone — инструкция</title>')
        ->toContain('<link rel="canonical" href="https://ruappstore.com/blog/kak-skachat-sber">')
        ->toContain('<meta property="og:type" content="article">')
        ->toContain('<meta property="og:image" content="https://ruappstore.com/assets/blog/sber.jpg">')
        ->toContain('<h2 id="pochemu-ego-net-v-app-store">Почему его нет в App Store</h2>')
        ->toContain('<a href="#sposoby-ustanovki">Способы установки</a>')
        ->toContain('обновлено')
        ->toContain('Приложения из статьи')
        ->toContain('href="/apps/sberbank-onlayn"')
        ->not->toContain('<script>alert(1)</script>');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);
    $graph = collect(json_decode($match[1], true)['@graph']);
    $article = $graph->firstWhere('@type', 'BlogPosting');
    expect($graph->pluck('@type')->all())->toBe(['Organization', 'WebSite', 'WebPage', 'BreadcrumbList', 'BlogPosting'])
        ->and($article['headline'])->toBe('Как скачать Сбер на iPhone')
        ->and($article['datePublished'])->toStartWith('2026-10-08')
        ->and($article['dateModified'])->toStartWith('2026-10-09')
        ->and($article['image']['url'])->toBe('https://ruappstore.com/assets/blog/sber.webp')
        ->and($article['about'][0]['url'])->toBe('https://ruappstore.com/apps/sberbank-onlayn');

    $this->get('/blog/no-such-article')->assertNotFound();
});

it('puts the blog and its articles in the sitemap', function () {
    $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($xml)->toContain('<loc>https://ruappstore.com/blog</loc>')
        ->toContain('<loc>https://ruappstore.com/blog/kak-skachat-sber</loc>')
        ->toContain('<image:loc>https://ruappstore.com/assets/blog/sber.webp</image:loc>');
});

it('reads every published article with what search engines need', function () {
    config(['seo.blog_path' => resource_path('blog')]);
    $posts = app(Blog::class)->all();

    expect($posts)->toHaveCount(6);
    foreach ($posts as $post) {
        expect(mb_strlen($post->metaTitle))->toBeLessThanOrEqual(70, $post->slug)
            ->and(mb_strlen($post->description))->toBeBetween(120, 200)
            ->and($post->keywords)->not->toBeEmpty()
            ->and($post->words)->toBeGreaterThan(450)
            ->and(count($post->toc))->toBeGreaterThanOrEqual(3)
            ->and(is_file(base_path('../front/public'.$post->image)))->toBeTrue($post->image.' is missing')
            ->and(is_file(base_path('../front/public'.$post->ogImage)))->toBeTrue($post->ogImage.' is missing');
    }
});
