<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ceiling to mirror min_ios_version: some re-signed builds (e.g. the legacy
 * "VK for old iPhones") crash on newer iOS, so an artifact can declare the
 * highest iOS it still supports and the install gate refuses anything above it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_artifacts', function (Blueprint $table) {
            $table->string('max_ios_version', 16)->nullable()->after('min_ios_version');
        });
    }

    public function down(): void
    {
        Schema::table('app_artifacts', function (Blueprint $table) {
            $table->dropColumn('max_ios_version');
        });
    }
};
