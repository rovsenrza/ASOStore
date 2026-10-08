<?php

namespace App\Console\Commands;

use App\Models\CatalogApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Loads our own write-ups for public app pages from a JSON file of {"seo_slug": "text"}.
 * Paragraphs are separated by blank lines. Touches the listing so the sitemap's lastmod moves.
 */
class SeoAboutCommand extends Command
{
    protected $signature = 'catalog:seo-about {file : JSON object of seo_slug => text} {--dry-run : Only print what would change}';

    protected $description = 'Set the seo_about text of public app pages from a JSON file';

    public function handle(): int
    {
        $texts = json_decode((string) @file_get_contents($this->argument('file')), true);
        if (! is_array($texts)) {
            $this->error('The file is not a JSON object.');

            return self::FAILURE;
        }

        $changed = 0;
        foreach ($texts as $slug => $text) {
            $app = CatalogApp::query()->where('seo_slug', $slug)->first();
            if ($app === null) {
                $this->warn("{$slug}\tno such page");

                continue;
            }
            $text = trim((string) $text);
            if ($app->seo_about === $text) {
                continue;
            }
            $this->line("{$slug}\t".mb_strlen($text).' chars');
            $changed++;
            if (! $this->option('dry-run')) {
                $app->forceFill(['seo_about' => $text])->saveQuietly();
                Cache::forget('seo:page:apps/'.$slug);
            }
        }
        $this->info(($this->option('dry-run') ? 'Would update ' : 'Updated ').$changed.' pages.');

        return self::SUCCESS;
    }
}
