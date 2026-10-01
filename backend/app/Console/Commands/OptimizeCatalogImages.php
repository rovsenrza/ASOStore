<?php

namespace App\Console\Commands;

use App\Models\CatalogApp;
use App\Services\Catalog\CatalogImageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class OptimizeCatalogImages extends Command
{
    protected $signature = 'catalog:optimize-images {--dry-run : Report what would change without writing} {--delete-originals : Remove the old files after the rows point at the new ones}';

    protected $description = 'Re-encode catalog icons and banners as small WebP files (new file names, so caches refresh)';

    public function handle(CatalogImageService $images): int
    {
        $before = $after = $changed = $failed = 0;

        CatalogApp::withTrashed()->orderBy('id')->each(function (CatalogApp $app) use ($images, &$before, &$after, &$changed, &$failed) {
            foreach (['icon_path' => 'optimizeStoredIcon', 'banner_path' => 'optimizeStoredBanner'] as $column => $method) {
                $path = $app->{$column};
                if ($path === null || str_ends_with($path, '.webp')) {
                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line("would re-encode {$app->slug} {$column}");

                    continue;
                }
                $result = $images->{$method}($path, $app->public_id);
                if ($result === null) {
                    $failed++;
                    $this->warn("skipped {$app->slug} {$column}: file missing or unreadable ({$path})");

                    continue;
                }
                // Straight to the table: the listing itself did not change, so updated_at (the "updated" shelf) must not move.
                DB::table('apps')->where('id', $app->id)->update([$column => $result['path']]);
                if ($this->option('delete-originals')) {
                    $images->delete($path);
                }
                $before += $result['bytes_before'];
                $after += $result['bytes_after'];
                $changed++;
            }
        });

        $this->info(sprintf('%d files re-encoded, %d skipped. %s → %s (%d%% smaller).',
            $changed, $failed, self::kb($before), self::kb($after), $before > 0 ? round((1 - $after / $before) * 100) : 0));

        return self::SUCCESS;
    }

    private static function kb(int $bytes): string
    {
        return number_format($bytes / 1024, 0, '.', ' ').' KB';
    }
}
