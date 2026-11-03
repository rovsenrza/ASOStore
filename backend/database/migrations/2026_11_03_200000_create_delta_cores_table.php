<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delta v3 (docs/adr/DELTA_V3.md): the bytes of an artifact's re-signed Mach-O files that every
        // team's build shares, stored once per artifact at cores/<sha256>.bin on the `signed` disk.
        Schema::create('delta_cores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artifact_id')->constrained('app_artifacts')->cascadeOnDelete();
            $table->char('sha256', 64)->unique();
            $table->unsignedBigInteger('size_bytes');
            $table->json('manifest');
            $table->string('storage_path');
            $table->timestamps();
            $table->index(['artifact_id', 'id']);
        });

        Schema::table('signed_builds', function (Blueprint $table) {
            // The core a `delta-v2` pack's "c" segments point into.
            $table->foreignId('delta_core_id')->nullable()->after('delivered_size')->constrained('delta_cores')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signed_builds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delta_core_id');
        });
        Schema::dropIfExists('delta_cores');
    }
};
