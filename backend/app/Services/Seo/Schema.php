<?php

namespace App\Services\Seo;

use Illuminate\Support\Carbon;

/**
 * schema.org nodes shared by every server-rendered public page. They reference each other by
 *
 * @id (the same ids the homepage uses), so search engines read one connected graph: the
 * organization, its website, this page, its breadcrumbs and what the page is about.
 */
class Schema
{
    public static function base(): string
    {
        return (string) config('seo.base_url');
    }

    /** @return array<string, mixed> */
    public static function organization(): array
    {
        $base = self::base();

        return [
            '@type' => 'Organization',
            '@id' => $base.'/#org',
            'name' => 'Ru App Store',
            'url' => $base.'/',
            'logo' => ['@type' => 'ImageObject', 'url' => $base.'/assets/brand/ru-app-store-logo-256.png', 'width' => 256, 'height' => 256],
            'sameAs' => ['https://t.me/ruappstors', 'https://t.me/RuAppStor_bot'],
            'contactPoint' => ['@type' => 'ContactPoint', 'contactType' => 'customer support', 'url' => 'https://t.me/suppruappstore', 'availableLanguage' => 'ru'],
        ];
    }

    /** @return array<string, mixed> */
    public static function website(): array
    {
        $base = self::base();

        return ['@type' => 'WebSite', '@id' => $base.'/#site', 'url' => $base.'/', 'name' => 'Ru App Store', 'inLanguage' => 'ru-RU', 'publisher' => ['@id' => $base.'/#org']];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function webPage(string $type, string $url, string $name, string $description, ?string $image = null, ?Carbon $modified = null, array $extra = []): array
    {
        $base = self::base();

        return array_filter([
            '@type' => $type,
            '@id' => $url,
            'url' => $url,
            'name' => $name,
            'description' => $description,
            'inLanguage' => 'ru-RU',
            'isPartOf' => ['@id' => $base.'/#site'],
            'publisher' => ['@id' => $base.'/#org'],
            'breadcrumb' => ['@id' => $url.'#breadcrumb'],
            'primaryImageOfPage' => $image ? ['@type' => 'ImageObject', 'url' => $image] : null,
            'dateModified' => $modified?->toAtomString(),
            ...$extra,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $trail  [name, absolute url] after the home page
     * @return array<string, mixed>
     */
    public static function breadcrumbs(string $url, array $trail): array
    {
        $items = [['Ru App Store', self::base().'/'], ...$trail];

        return ['@type' => 'BreadcrumbList', '@id' => $url.'#breadcrumb', 'itemListElement' => array_map(fn (array $item, int $i) => [
            '@type' => 'ListItem', 'position' => $i + 1, 'name' => $item[0], 'item' => $item[1],
        ], $items, array_keys($items))];
    }

    /**
     * The steps from buying access to installing an app, for a HowTo node.
     *
     * @return list<array<string, string>>
     */
    public static function installSteps(string $appName, ?int $price): array
    {
        $base = self::base();

        return [
            ['@type' => 'HowToStep', 'position' => '1', 'name' => 'Купите доступ к Ru App Store', 'url' => $base.'/buy.html',
                'text' => 'Выберите тариф'.($price ? " (от {$price} ₽ в месяц)" : '').': любой открывает весь каталог.'],
            ['@type' => 'HowToStep', 'position' => '2', 'name' => 'Зарегистрируйте iPhone', 'url' => $base.'/activate.html',
                'text' => 'Откройте сайт в Safari и установите профиль регистрации устройства.'],
            ['@type' => 'HowToStep', 'position' => '3', 'name' => 'Установите приложение Ru App Store', 'url' => $base.'/install.html',
                'text' => 'Ru App Store появится на экране «Домой», как обычное приложение.'],
            ['@type' => 'HowToStep', 'position' => '4', 'name' => "Установите {$appName}",
                'text' => "Найдите «{$appName}» в каталоге Ru App Store и нажмите «Установить»."],
        ];
    }
}
