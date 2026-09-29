<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A rejected file may be uploaded again once the reason is fixed, so the same hash can
     * appear on a discarded artifact and a new one. ChunkedUploadService still refuses a
     * second live copy.
     */
    public function up(): void
    {
        Schema::table('app_artifacts', function (Blueprint $table) {
            $table->dropUnique(['sha256']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('app_artifacts', function (Blueprint $table) {
            $table->dropIndex(['sha256']);
            $table->unique('sha256');
        });
    }
};
