<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Name, developer, category and icon of an app from Apple's public lookup, so a listing created
 * from an IPA is not left bare. Strictly best-effort: any failure returns null and the listing
 * is built from what the IPA itself says.
 */
class StoreMetadataLookup
{
    /** Apple's primary genre → our category slug. A genre not listed is left to the operator. */
    private const GENRES = [
        'Productivity' => 'productivity',
        'Photo & Video' => 'photo-video',
        'Health & Fitness' => 'health',
        'Travel' => 'travel',
        'Weather' => 'weather',
        'Music' => 'music',
        'Business' => 'business',
        'Utilities' => 'utilities',
        'Education' => 'education',
        'Social Networking' => 'social',
        'Finance' => 'finance',
        'Entertainment' => 'entertainment',
        'Games' => 'games-arcade',
    ];

    public function enabled(): bool
    {
        return (bool) config('storefront.quick_publish.lookup_enabled', true);
    }

    /**
     * @return array{name: string, publisher: ?string, category_slug: ?string, icon_url: ?string, app_store_id: ?string}|null
     */
    public function find(string $bundleIdentifier): ?array
    {
        if (! $this->enabled() || $bundleIdentifier === '') {
            return null;
        }
        try {
            $response = Http::timeout((int) config('storefront.quick_publish.lookup_timeout', 8))->acceptJson()->get('https://itunes.apple.com/lookup', [
                'bundleId' => $bundleIdentifier,
                'country' => config('storefront.quick_publish.lookup_country', 'ru'),
                'entity' => 'software',
            ]);
            $row = $response->successful() ? ($response->json('results.0') ?? null) : null;
        } catch (Throwable $failure) {
            Log::info('quick_publish.lookup_failed', ['bundle' => $bundleIdentifier, 'error' => $failure->getMessage()]);

            return null;
        }
        if (! is_array($row) || ! is_string($row['trackName'] ?? null) || $row['trackName'] === '') {
            return null;
        }

        return [
            'name' => trim($row['trackName']),
            'publisher' => is_string($row['artistName'] ?? null) && trim($row['artistName']) !== '' ? trim($row['artistName']) : null,
            'category_slug' => self::GENRES[(string) ($row['primaryGenreName'] ?? '')] ?? null,
            'icon_url' => is_string($row['artworkUrl512'] ?? null) ? $row['artworkUrl512'] : (is_string($row['artworkUrl100'] ?? null) ? $row['artworkUrl100'] : null),
            'app_store_id' => isset($row['trackId']) ? (string) $row['trackId'] : null,
        ];
    }

    /** The icon image, only from Apple's own image host. */
    public function icon(?string $url): ?string
    {
        if ($url === null || ! $this->enabled()) {
            return null;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ($host !== 'mzstatic.com' && ! str_ends_with($host, '.mzstatic.com'))) {
            return null;
        }
        try {
            $response = Http::timeout((int) config('storefront.quick_publish.lookup_timeout', 8))->get($url);
        } catch (Throwable) {
            return null;
        }
        $bytes = $response->successful() ? $response->body() : '';

        return $bytes !== '' && strlen($bytes) < 5 * 1024 * 1024 && @getimagesizefromstring($bytes) !== false ? $bytes : null;
    }
}
