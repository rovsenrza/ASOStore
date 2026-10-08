<?php

namespace App\Console\Commands;

use App\Enums\AppVisibility;
use App\Models\CatalogApp;
use App\Services\Seo\SeoSlugs;
use Illuminate\Console\Command;

/**
 * Gives every published listing without one its readable page address (new listings get it when
 * published). The most prominent listing of a name gets the plain slug; later ones a suffix.
 */
class SeoSlugsCommand extends Command
{
    protected $signature = 'catalog:seo-slugs {--dry-run : Only print what would be assigned}';

    protected $description = 'Assign readable /apps/{slug} addresses to published listings that have none';

    public function handle(SeoSlugs $slugs): int
    {
        $apps = CatalogApp::query()
            ->where('visibility', AppVisibility::Published->value)
            ->whereNull('seo_slug')
            ->withCount('installations')
            ->orderByRaw('featured_rank is null, featured_rank')
            ->orderByDesc('installations_count')
            ->orderBy('id')
            ->get();

        foreach ($apps as $app) {
            $slug = $slugs->unique($app->name, $app->id);
            $this->line("{$app->id}\t{$slug}\t{$app->name}");
            if (! $this->option('dry-run')) {
                $app->timestamps = false;
                $app->forceFill(['seo_slug' => $slug])->saveQuietly();
            }
        }
        $this->info(($this->option('dry-run') ? 'Would assign ' : 'Assigned ').$apps->count().' addresses.');

        return self::SUCCESS;
    }
}
