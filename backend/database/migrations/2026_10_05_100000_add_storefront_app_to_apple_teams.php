<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apple_teams', function (Blueprint $table) {
            // Each team receives a separately built Ru AppStore IPA with its own Bundle ID.
            $table->foreignId('storefront_app_id')->nullable()->unique()->constrained('apps')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('apple_teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storefront_app_id');
        });
    }
};
