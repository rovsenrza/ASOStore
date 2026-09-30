<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The brand is now spelled "Ru App Store". Only names still carrying the
 * old spelling change; anything an operator renamed is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('apps')->where('is_storefront', true)->where('name', 'Ru AppStore')->update(['name' => 'Ru App Store', 'updated_at' => now()]);
        DB::table('app_publishers')->where('name', 'Ru AppStore')->update(['name' => 'Ru App Store', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('apps')->where('is_storefront', true)->where('name', 'Ru App Store')->update(['name' => 'Ru AppStore', 'updated_at' => now()]);
        DB::table('app_publishers')->where('name', 'Ru App Store')->update(['name' => 'Ru AppStore', 'updated_at' => now()]);
    }
};
