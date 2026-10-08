<?php

namespace App\Console\Commands;

use App\Services\Seo\PublicUrls;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells Yandex (and Bing, which share IndexNow) about new and changed public pages, so they are
 * crawled within hours instead of whenever the sitemap is next read.
 */
class IndexNowCommand extends Command
{
    protected $signature = 'seo:indexnow {--all : Submit every public page} {--hours=25 : Pages changed within this many hours}';

    protected $description = 'Submit new and changed public pages to IndexNow';

    public function handle(PublicUrls $urls): int
    {
        $key = (string) config('seo.indexnow.key');
        if ($key === '') {
            $this->warn('SEO_INDEXNOW_KEY is not set; nothing submitted.');

            return self::SUCCESS;
        }

        $list = array_column($urls->all($this->option('all') ? null : now()->subHours((int) $this->option('hours'))), 'loc');
        if ($list === []) {
            $this->info('No changed pages.');

            return self::SUCCESS;
        }

        $base = config('seo.base_url');
        try {
            $response = Http::timeout(30)->acceptJson()->post((string) config('seo.indexnow.endpoint'), [
                'host' => parse_url($base, PHP_URL_HOST),
                'key' => $key,
                'keyLocation' => $base.'/'.$key.'.txt',
                'urlList' => array_slice($list, 0, 10000),
            ]);
        } catch (Throwable $e) {
            Log::warning('seo.indexnow_failed', ['message' => $e->getMessage()]);
            $this->error('IndexNow unreachable: '.$e->getMessage());

            return self::FAILURE;
        }

        Log::info('seo.indexnow', ['urls' => count($list), 'status' => $response->status()]);
        $this->info('Submitted '.count($list).' pages: HTTP '.$response->status());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
