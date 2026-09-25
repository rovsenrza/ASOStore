<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_categories', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('slug', 64)->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('app_publishers', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('website')->nullable();
            $table->string('support_email')->nullable();
            $table->timestamps();
        });

        Schema::create('apps', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('slug', 96)->unique();
            $table->string('name');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained('app_categories')->restrictOnDelete();
            $table->foreignId('publisher_id')->constrained('app_publishers')->restrictOnDelete();
            // FULL_PLAN §5.1: every listing declares one source type.
            $table->string('source_type', 40);
            $table->string('visibility', 16)->default('DRAFT');
            $table->string('age_rating', 8)->default('4+');
            $table->string('icon_path')->nullable();
            // The native Storefront is itself a catalog item (IMPLEMENTATION_PLAN §5.6).
            $table->boolean('is_storefront')->default(false);
            $table->unsignedSmallInteger('featured_rank')->nullable();
            $table->timestamps();
            // FULL_PLAN §7: soft delete only for user-facing catalog records.
            $table->softDeletes();

            $table->index(['visibility', 'deleted_at']);
            $table->index('featured_rank');
        });

        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('app_id')->constrained('apps')->restrictOnDelete();
            $table->string('version', 32);
            $table->string('build_number', 32);
            $table->text('release_notes')->nullable();
            $table->string('min_ios_version', 16)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->unique(['app_id', 'version', 'build_number']);
            $table->index(['app_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
        Schema::dropIfExists('apps');
        Schema::dropIfExists('app_publishers');
        Schema::dropIfExists('app_categories');
    }
};
