<?php

namespace App\Services\Seo;

use App\Models\CatalogApp;
use Illuminate\Support\Str;

/**
 * Readable Latin addresses for public app pages, transliterated the way Russian search engines
 * show them («Яндекс Музыка» → yandeks-muzyka), unique across the catalog.
 */
class SeoSlugs
{
    private const MAX_LENGTH = 60;

    private const CYRILLIC = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e', 'ж' => 'zh',
        'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o',
        'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu',
        'я' => 'ya', 'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g', 'ә' => 'a', 'ө' => 'o', 'ү' => 'u',
    ];

    public function base(string $name): string
    {
        $slug = Str::slug(strtr(mb_strtolower($name), self::CYRILLIC));
        if (strlen($slug) > self::MAX_LENGTH) {
            $cut = substr($slug, 0, self::MAX_LENGTH + 1);
            $slug = substr($cut, 0, strrpos($cut, '-') ?: self::MAX_LENGTH);
        }

        return $slug !== '' ? $slug : 'app';
    }

    public function unique(string $name, ?int $ignoreId = null): string
    {
        $base = $this->base($name);
        $slug = $base;
        for ($n = 2; $this->taken($slug, $ignoreId); $n++) {
            $slug = "{$base}-{$n}";
        }

        return $slug;
    }

    private function taken(string $slug, ?int $ignoreId): bool
    {
        return CatalogApp::withTrashed()
            ->where('seo_slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
