<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catalog media (IMPLEMENTATION_PLAN P4-BE-01) and the bundle identifier a
     * listing is bound to once its first IPA is inspected (Phase 5).
     */
    public function up(): void
    {
        Schema::create('app_screenshots', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['app_id', 'sort_order']);
        });

        Schema::table('apps', function (Blueprint $table) {
            $table->string('bundle_identifier')->nullable()->unique()->after('slug');
            $table->string('support_url')->nullable()->after('icon_path');
            $table->string('privacy_url')->nullable()->after('support_url');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropUnique(['bundle_identifier']);
            $table->dropColumn(['bundle_identifier', 'support_url', 'privacy_url']);
        });
        Schema::dropIfExists('app_screenshots');
    }
};
