<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The database cache store only drops an expired row when that key is read again, and most keys
 * (worker nonces, one-off throttles) never are, so the table keeps growing. Deletes expired rows
 * and expired locks in small batches so the table is never locked for long. Long-lived rows
 * (Apple portal groups, alert and bot state) have no near expiry and stay.
 */
class PruneDatabaseCache extends Command
{
    protected $signature = 'cache:prune-database {--batch=5000 : Rows deleted per statement}';

    protected $description = 'Delete expired rows from the database cache and cache lock tables (also runs hourly)';

    public function handle(): int
    {
        $store = config('cache.stores.database');
        $connection = DB::connection($store['connection'] ?? null);
        $batch = max(100, (int) $this->option('batch'));
        $now = time();

        $deleted = [];
        foreach (array_filter([$store['table'] ?? 'cache', $store['lock_table'] ?? 'cache_locks']) as $table) {
            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                continue;
            }
            $deleted[$table] = 0;
            do {
                $keys = $connection->table($table)->where('expiration', '<=', $now)->limit($batch)->pluck('key');
                $count = $keys->isEmpty() ? 0 : $connection->table($table)->whereIn('key', $keys)->where('expiration', '<=', $now)->delete();
                $deleted[$table] += $count;
            } while ($keys->count() === $batch);
        }

        foreach ($deleted as $table => $count) {
            $this->line("{$table}: {$count} expired rows deleted.");
        }

        return self::SUCCESS;
    }
}
