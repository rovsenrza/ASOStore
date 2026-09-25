<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Games and apps are separate storefront tabs; a category belongs to one of them.
     */
    public function up(): void
    {
        Schema::table('app_categories', function (Blueprint $table) {
            $table->string('kind', 8)->default('APPS')->after('subtitle');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::table('app_categories', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
