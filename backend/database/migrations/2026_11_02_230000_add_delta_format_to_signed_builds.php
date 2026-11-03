<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signed_builds', function (Blueprint $table) {
            // `full`: storage_path is the whole signed IPA. `delta-v1`: it is a pack of the entries
            // that re-signing changed, and the IPA is composed from it and the artifact's original
            // when it is delivered (docs/adr/DELTA_BUILDS.md).
            $table->string('format', 16)->default('full')->after('status_reason');
            // Size of the composed IPA (the Content-Length of a delta build; size_bytes is the pack's).
            $table->unsignedBigInteger('delivered_size')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('signed_builds', function (Blueprint $table) {
            $table->dropColumn(['format', 'delivered_size']);
        });
    }
};
