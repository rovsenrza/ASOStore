<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Profile lookups by bundle identifier (newest first) scanned the whole table, whose rows
     * each carry a provisioning profile: the most frequent slow query in production.
     */
    public function up(): void
    {
        if (Schema::hasIndex('signing_profiles', 'signing_profiles_bundle_identifier_index')) {
            return; // Added by hand in production on 2026-11-11.
        }
        Schema::table('signing_profiles', function (Blueprint $table) {
            $table->index('bundle_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('signing_profiles', function (Blueprint $table) {
            $table->dropIndex('signing_profiles_bundle_identifier_index');
        });
    }
};
