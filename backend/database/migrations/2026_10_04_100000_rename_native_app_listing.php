<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update only the old demo listing; preserve any name an operator chose.
        $app = DB::table('apps')
            ->where('slug', 'storefront')
            ->where('is_storefront', true)
            ->whereIn('name', ['Storefront', '[BRAND] Storefront', 'Ru AppStore Storefront'])
            ->first(['id', 'publisher_id']);

        if ($app === null) {
            return;
        }

        DB::table('apps')->where('id', $app->id)->update(['name' => 'Ru AppStore', 'updated_at' => now()]);

        // The initial demo seed could also leave a placeholder publisher.
        if (DB::table('apps')->where('publisher_id', $app->publisher_id)->count() === 1) {
            DB::table('app_publishers')
                ->where('id', $app->publisher_id)
                ->where('name', '[BRAND]')
                ->update(['name' => 'Ru AppStore', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Restoring the old customer-facing brand would be a data regression.
    }
};
