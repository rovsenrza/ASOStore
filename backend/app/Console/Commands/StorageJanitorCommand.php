<?php

namespace App\Console\Commands;

use App\Services\Artifacts\StorageJanitor;
use Illuminate\Console\Command;

class StorageJanitorCommand extends Command
{
    protected $signature = 'storage:janitor {--dry-run : Report what would be removed without changing anything} {--sweep : List the bucket for leftover objects now, not on the six-hour cadence}';

    protected $description = 'Remove idle signed builds, superseded originals, leftover objects and stale temporary copies (also runs every ten minutes)';

    public function handle(StorageJanitor $janitor): int
    {
        $summary = $janitor->run((bool) $this->option('dry-run'), (bool) $this->option('sweep'));
        $report = $janitor->report();

        $this->table(['', 'count', 'bytes'], [
            ['idle signed builds', $summary['idle_builds'], ''],
            ['signed builds over budget', $summary['budget_builds'], ''],
            ['signed build files removed', $summary['builds_purged'], self::gb($summary['build_bytes'])],
            ['superseded originals removed', $summary['originals_purged'], self::gb($summary['original_bytes'])],
            ['leftover objects', $summary['orphan_objects'], self::gb($summary['orphan_bytes'])],
            ['abandoned multipart uploads', $summary['multipart_aborted'], ''],
            ['stale temporary copies', $summary['temp_files'], ''],
            ['cached copies dropped', $summary['cache_files'], ''],
            ['errors (see the log)', $summary['errors'], ''],
        ]);
        $this->line(sprintf('%sSigned builds now: %s of %s budget. Disk free: %s.',
            $this->option('dry-run') ? 'Dry run, nothing changed. ' : '',
            self::gb($report['signed_builds']['bytes']), self::gb($report['signed_builds']['budget_bytes']), self::gb($report['disk']['free_bytes'])));

        return $summary['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private static function gb(int $bytes): string
    {
        return number_format($bytes / 1024 ** 3, 2, '.', ' ').' GB';
    }
}
