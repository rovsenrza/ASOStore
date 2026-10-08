<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // The readable address of the public app page (/apps/{seo_slug}); `slug` stays internal.
            $table->string('seo_slug', 80)->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropUnique(['seo_slug']);
            $table->dropColumn('seo_slug');
        });
    }
};
