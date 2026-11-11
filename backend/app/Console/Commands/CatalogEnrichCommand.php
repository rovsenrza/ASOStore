<?php

namespace App\Console\Commands;

use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Services\Audit\Actor;
use App\Services\Audit\AuditService;
use App\Services\Catalog\AppStoreImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Fills the gaps in catalog cards from the app's own App Store listing: a missing description,
 * missing screenshots, missing release notes, an unknown developer, a missing support link.
 *
 * Only an exact match counts: the listing's App Store ID, or the bundle ID of the published IPA.
 * Nothing a person wrote is overwritten, and release notes are taken only when the App Store
 * version is the one we publish. Without --apply it only reports what it would do.
 */
class CatalogEnrichCommand extends Command
{
    protected $signature = 'catalog:enrich {--apply : Write the changes (default: report only)} {--app= : One listing, by public ID} {--limit=0 : Stop after this many listings with gaps} {--pause=3 : Seconds between App Store lookups}';

    protected $description = 'Fill missing descriptions, screenshots, release notes and developers from the App Store';

    private const LOOKUP = 'https://itunes.apple.com/lookup';

    private const UNKNOWN_PUBLISHER = 'Разработчик не указан';

    public function handle(AppStoreImporter $importer, AuditService $audit): int
    {
        $apply = (bool) $this->option('apply');
        $apps = CatalogApp::query()->publiclyBrowsable()
            ->with(['publisher', 'latestVersion', 'publishedArtifact'])->withCount('screenshots')
            ->when($this->option('app'), fn ($query, $id) => $query->where('public_id', strtolower((string) $id)))
            ->orderBy('id')->get();

        $totals = ['gaps' => 0, 'matched' => 0, 'description' => 0, 'screenshots' => 0, 'release_notes' => 0, 'publisher' => 0, 'support_url' => 0];
        foreach ($apps as $app) {
            $gaps = $this->gaps($app);
            if ($gaps === []) {
                continue;
            }
            if ((int) $this->option('limit') > 0 && $totals['gaps'] >= (int) $this->option('limit')) {
                break;
            }
            $totals['gaps']++;

            $listing = $this->listing($app);
            sleep(max(0, (int) $this->option('pause')));
            if ($listing === null) {
                $this->line("NO-MATCH  {$app->name}  [".implode(',', $gaps).']');

                continue;
            }
            $totals['matched']++;

            $done = [];
            try {
                $done = $this->fill($app, $gaps, $listing, $apply, $importer);
            } catch (Throwable $exception) {
                $this->warn("ERROR     {$app->name}: {$exception->getMessage()}");

                continue;
            }
            foreach (array_keys($done) as $field) {
                $totals[$field]++;
            }
            if ($apply && $done !== []) {
                $audit->record('app.enriched', $app, after: $done + ['source' => 'app-store:'.($listing['trackId'] ?? '?')], actor: Actor::system('catalog-enrich'));
            }
            $this->line(($done === [] ? 'NOTHING   ' : ($apply ? 'FILLED    ' : 'WOULD     ')).$app->name.'  '.json_encode($done, JSON_UNESCAPED_UNICODE));
        }

        $this->info(($apply ? 'APPLIED ' : 'DRY-RUN ').json_encode($totals));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function gaps(CatalogApp $app): array
    {
        return array_keys(array_filter([
            'description' => blank($app->description),
            'screenshots' => $app->screenshots_count < 2,
            'release_notes' => $app->latestVersion !== null && blank($app->latestVersion->release_notes),
            'publisher' => $app->publisher->name === self::UNKNOWN_PUBLISHER,
            'support_url' => blank($app->support_url),
        ]));
    }

    /**
     * The app's own App Store listing, in Russian when there is one, or null.
     *
     * @return array<string, mixed>|null
     */
    private function listing(CatalogApp $app): ?array
    {
        $bundle = $app->publishedArtifact?->bundle_identifier;
        $query = filled($app->app_store_id) ? ['id' => $app->app_store_id] : (filled($bundle) ? ['bundleId' => $bundle] : null);
        if ($query === null) {
            return null;
        }

        foreach (['ru', 'us'] as $storefront) {
            try {
                $response = Http::timeout(15)->retry(2, 2000, throw: false)->acceptJson()
                    ->get(self::LOOKUP, $query + ['country' => $storefront, 'lang' => 'ru_ru', 'entity' => 'software']);
            } catch (Throwable) {
                continue;
            }
            $result = $response->successful() ? $response->json('results.0') : null;
            if (! is_array($result) || ($result['kind'] ?? null) !== 'software') {
                continue;
            }
            // A lookup by bundle ID must return that very bundle; anything else is another app.
            if (isset($query['bundleId']) && strcasecmp((string) ($result['bundleId'] ?? ''), (string) $bundle) !== 0) {
                continue;
            }

            return $result;
        }

        return null;
    }

    /**
     * @param  list<string>  $gaps
     * @param  array<string, mixed>  $listing
     * @return array<string, mixed> what was (or would be) filled
     */
    private function fill(CatalogApp $app, array $gaps, array $listing, bool $apply, AppStoreImporter $importer): array
    {
        $done = [];
        // A modified build is not the developer's release: its notes and seller would mislead.
        $modified = preg_match('/\bmod\b|\+|\bport\b|plus|premium|unlocked/iu', $app->name) === 1 || str_starts_with($app->publisher->name, '@');

        $description = mb_substr(trim((string) ($listing['description'] ?? '')), 0, 4000);
        if (in_array('description', $gaps, true) && $description !== '') {
            $done['description'] = mb_strlen($description);
            if ($apply) {
                $app->forceFill(['description' => $description])->save();
            }
        }

        $shots = array_values(array_filter((array) ($listing['screenshotUrls'] ?? []), 'is_string'));
        if (in_array('screenshots', $gaps, true) && $shots !== []) {
            $done['screenshots'] = $apply ? $importer->addScreenshots($app, $shots) : min(count($shots), (int) config('storefront.catalog.screenshot_max_count'));
            if ($done['screenshots'] === 0) {
                unset($done['screenshots']);
            }
        }

        $notes = mb_substr(trim((string) ($listing['releaseNotes'] ?? '')), 0, 4000);
        $version = $app->latestVersion;
        if (in_array('release_notes', $gaps, true) && ! $modified && $notes !== '' && $version !== null
            && version_compare((string) ($listing['version'] ?? ''), (string) $version->version, '==')) {
            $done['release_notes'] = mb_strlen($notes);
            if ($apply) {
                $version->forceFill(['release_notes' => $notes])->save();
            }
        }

        $seller = mb_substr(trim((string) ($listing['sellerName'] ?? $listing['artistName'] ?? '')), 0, 255);
        if (in_array('publisher', $gaps, true) && ! $modified && $seller !== '') {
            $done['publisher'] = $seller;
            if ($apply) {
                $app->forceFill(['publisher_id' => AppPublisher::firstOrCreate(['name' => $seller])->id])->save();
            }
        }

        $url = $listing['sellerUrl'] ?? null;
        if (in_array('support_url', $gaps, true) && ! $modified && is_string($url) && str_starts_with($url, 'https://') && mb_strlen($url) <= 255) {
            $done['support_url'] = $url;
            if ($apply) {
                $app->forceFill(['support_url' => $url])->save();
            }
        }

        return $done;
    }
}
