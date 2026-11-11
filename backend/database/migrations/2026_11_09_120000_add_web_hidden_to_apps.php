<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Hide a published listing from the public website (/apps, /categories, sitemap, blog) while
            // keeping it available in the app. Set for listings that drew a trademark/abuse complaint about
            // the public page, so the web resource is removed without unpublishing the app for customers.
            $table->boolean('web_hidden')->default(false)->after('is_storefront');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('web_hidden');
        });
    }
};
