<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // The App Store listing a card was imported from (AppStoreImporter); one card per listing.
            $table->string('app_store_id', 20)->nullable()->unique()->after('bundle_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropUnique(['app_store_id']);
            $table->dropColumn('app_store_id');
        });
    }
};
