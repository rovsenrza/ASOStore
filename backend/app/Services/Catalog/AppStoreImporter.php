<?php

namespace App\Services\Catalog;

use App\Enums\AppVisibility;
use App\Enums\CategoryKind;
use App\Enums\ErrorCode;
use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Models\User;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates a draft listing from an App Store link: name, description (Russian when
 * the listing has it), category, publisher, age rating, icon and screenshots, plus a
 * com.ruappstore.* signing bundle ID the primary team is approved for. The operator
 * then uploads the IPA; publishing it publishes the listing.
 */
class AppStoreImporter
{
    private const LOOKUP = 'https://itunes.apple.com/lookup';

    /** App Store primaryGenreId → our category slug. Games go by their sub-genre. */
    private const GENRES = [
        6000 => 'business', 6001 => 'weather', 6002 => 'utilities', 6003 => 'travel', 6005 => 'social',
        6007 => 'productivity', 6008 => 'photo-video', 6011 => 'music', 6013 => 'health', 6015 => 'finance',
        6016 => 'entertainment', 6017 => 'education',
    ];

    private const GAME_GENRES = [7001 => 'games-action', 7003 => 'games-arcade', 7012 => 'games-puzzle', 7013 => 'games-racing'];

    public function __construct(
        private readonly CatalogImageService $images,
        private readonly AuditService $audit,
        private readonly TeamEligibilityGranter $eligibility,
    ) {}

    /**
     * @return array{app: CatalogApp, warnings: list<string>}
     */
    public function import(string $link, User $actor, SourceType $sourceType): array
    {
        [$id, $country] = self::parse($link);
        if (($existing = CatalogApp::withTrashed()->where('app_store_id', $id)->first()) !== null) {
            throw new ApiException(ErrorCode::Conflict, 'Это приложение уже добавлено: «'.$existing->name.'».', ['app_id' => $existing->public_id]);
        }
        $listing = $this->lookup($id, $country);

        $app = DB::transaction(function () use ($listing, $id, $link, $actor, $sourceType) {
            $name = mb_substr(trim((string) $listing['trackName']), 0, 100);
            $slug = $this->uniqueSlug($name);
            $app = CatalogApp::create([
                'slug' => $slug,
                'name' => $name,
                'description' => mb_substr(trim((string) ($listing['description'] ?? '')), 0, 4000) ?: null,
                'app_store_id' => $id,
                'bundle_identifier' => $this->signingBundleIdentifier($slug),
                'category_id' => $this->category($listing)->id,
                'publisher_id' => AppPublisher::firstOrCreate(['name' => mb_substr(trim((string) ($listing['sellerName'] ?? $listing['artistName'] ?? 'App Store')), 0, 255)])->id,
                'source_type' => $sourceType,
                'visibility' => AppVisibility::Draft,
                'age_rating' => self::ageRating((string) ($listing['contentAdvisoryRating'] ?? '')),
                'support_url' => self::https($listing['sellerUrl'] ?? null),
            ]);
            $this->audit->record('app.imported', $app, after: [
                'name' => $app->name, 'app_store_id' => $id, 'link' => $link, 'bundle_identifier' => $app->bundle_identifier,
            ], actor: Actor::user($actor));

            // The signing ID is ours (com.ruappstore.*), so the primary team may sign it.
            $this->eligibility->grantFor($app, $actor, "Imported from the App Store ({$id}); signed as our own bundle ID.");

            return $app;
        });

        // Images after the listing exists: a failed download leaves a card to fix by hand.
        $warnings = [];
        try {
            $icon = $this->withDownload(self::resize((string) ($listing['artworkUrl512'] ?? $listing['artworkUrl100'] ?? ''), '1024x1024bb.png'),
                fn (UploadedFile $file) => $this->images->storeIcon($file, $app->public_id));
            $app->forceFill(['icon_path' => $icon['path']])->save();
        } catch (Throwable) {
            $warnings[] = 'Не удалось загрузить иконку — добавьте её вручную.';
        }

        $shots = (array) ($listing['screenshotUrls'] ?? []);
        $added = $this->addScreenshots($app, $shots);
        if ($added < min(count($shots), (int) config('storefront.catalog.screenshot_max_count'))) {
            $warnings[] = 'Не все скриншоты загрузились.';
        }
        if (! self::hasRussian($listing)) {
            $warnings[] = 'В App Store нет русского описания — переведите его в карточке.';
        }

        return ['app' => $app->refresh(), 'warnings' => $warnings];
    }

    /**
     * App Store ID and storefront country from a link such as
     * https://apps.apple.com/us/app/amneziavpn/id1600529900, or a bare ID.
     *
     * @return array{0: string, 1: string}
     */
    /**
     * Appends App Store screenshots (mzstatic URLs) after the card's own, up to the maximum.
     * Returns how many were added; one that fails to download is skipped.
     *
     * @param  list<string>  $urls
     */
    public function addScreenshots(CatalogApp $app, array $urls): int
    {
        $room = (int) config('storefront.catalog.screenshot_max_count') - $app->screenshots()->count();
        $order = (int) $app->screenshots()->max('sort_order');
        $added = 0;
        foreach (array_slice(array_values($urls), 0, max(0, $room)) as $url) {
            try {
                $stored = $this->withDownload(self::resize((string) $url, '1290x0w.jpg'),
                    fn (UploadedFile $file) => $this->images->storeScreenshot($file, $app->public_id));
                $app->screenshots()->create($stored + ['sort_order' => $order + $added + 1]);
                $added++;
            } catch (Throwable) {
                continue;
            }
        }

        return $added;
    }

    public static function parse(string $link): array
    {
        $link = trim($link);
        if (preg_match('/^\d{5,15}$/', $link) === 1) {
            return [$link, 'ru'];
        }

        $parts = parse_url($link);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (! in_array($host, ['apps.apple.com', 'itunes.apple.com'], true) || preg_match('#/id(\d{5,15})#', (string) ($parts['path'] ?? ''), $id) !== 1) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Нужна ссылка на приложение в App Store, например https://apps.apple.com/ru/app/…/id123456789.', ['fields' => ['url' => ['Not an App Store app link.']]]);
        }
        $country = preg_match('#^/([a-z]{2})/#', (string) $parts['path'], $match) === 1 ? $match[1] : 'ru';

        return [$id[1], $country];
    }

    /**
     * The listing in Russian when possible: the link's storefront first, then the
     * Russian and US ones (an app missing from one storefront is often in another).
     *
     * @return array<string, mixed>
     */
    private function lookup(string $id, string $country): array
    {
        foreach (array_unique([$country, 'ru', 'us']) as $storefront) {
            $response = Http::timeout(15)->acceptJson()->get(self::LOOKUP, ['id' => $id, 'country' => $storefront, 'lang' => 'ru_ru', 'entity' => 'software']);
            $result = $response->successful() ? ($response->json('results.0') ?? null) : null;
            if (is_array($result) && ($result['kind'] ?? null) === 'software' && filled($result['trackName'] ?? null)) {
                return $result;
            }
        }

        throw new ApiException(ErrorCode::NotFound, 'Приложение не найдено в App Store.');
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function category(array $listing): AppCategory
    {
        $primary = (int) ($listing['primaryGenreId'] ?? 0);
        $genres = array_map('intval', (array) ($listing['genreIds'] ?? []));
        $isGame = $primary === 6014 || in_array(6014, $genres, true);

        $slug = self::GENRES[$primary] ?? null;
        if ($isGame) {
            foreach ($genres as $genre) {
                $slug = self::GAME_GENRES[$genre] ?? null;
                if ($slug !== null) {
                    break;
                }
            }
        }
        if ($slug !== null && ($category = AppCategory::query()->where('slug', $slug)->first()) !== null) {
            return $category;
        }

        // A genre we have no category for yet: add one under App Store's (Russian) genre name.
        $title = trim((string) ($listing['primaryGenreName'] ?? '')) ?: ($isGame ? 'Игры' : 'Другое');

        return AppCategory::query()->firstOrCreate(
            ['slug' => 'appstore-'.($primary ?: 'other')],
            ['title' => mb_substr($title, 0, 100), 'kind' => $isGame ? CategoryKind::Games : CategoryKind::Apps, 'sort_order' => 100],
        );
    }

    private function signingBundleIdentifier(string $slug): string
    {
        $base = config('storefront.artifacts.own_bundle_prefix').(trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($slug)), '-') ?: 'app');
        $candidate = $base;
        for ($i = 2; CatalogApp::withTrashed()->where('bundle_identifier', $candidate)->exists(); $i++) {
            $candidate = "{$base}{$i}";
        }

        return $candidate;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name, language: 'ru') ?: 'app';
        $slug = $base;
        for ($i = 2; CatalogApp::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * Downloads an App Store image (Apple's image CDN only) to a temporary file,
     * hands it to $use and removes it.
     *
     * @template T
     *
     * @param  callable(UploadedFile): T  $use
     * @return T
     */
    private function withDownload(string $url, callable $use): mixed
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! str_ends_with($host, '.mzstatic.com')) {
            throw new \RuntimeException('Not an App Store image.');
        }

        $response = Http::timeout(30)->get($url)->throw();
        $path = (string) tempnam(sys_get_temp_dir(), 'appstore-');
        try {
            file_put_contents($path, $response->body());

            return $use(new UploadedFile($path, basename((string) parse_url($url, PHP_URL_PATH)), null, null, true));
        } finally {
            @unlink($path);
        }
    }

    /** mzstatic URLs end in a size spec (…/512x512bb.jpg); ask for a larger rendition. */
    private static function resize(string $url, string $spec): string
    {
        return (string) preg_replace('#/[^/]+$#', '/'.$spec, $url);
    }

    private static function ageRating(string $rating): string
    {
        $years = (int) $rating;

        return match (true) {
            $years >= 17 => '17+',
            $years >= 12 => '12+',
            $years >= 9 => '9+',
            default => '4+',
        };
    }

    private static function https(mixed $url): ?string
    {
        return is_string($url) && str_starts_with($url, 'https://') && mb_strlen($url) <= 255 ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private static function hasRussian(array $listing): bool
    {
        return preg_match('/\p{Cyrillic}/u', (string) ($listing['description'] ?? '')) === 1;
    }
}
