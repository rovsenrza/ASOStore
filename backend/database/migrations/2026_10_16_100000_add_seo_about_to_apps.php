<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Our own write-up for the public app page; `description` is the vendor's App Store text.
            $table->text('seo_about')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('seo_about');
        });
    }
};
