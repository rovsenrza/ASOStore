<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            // Since when its team builds go out as delta packs whatever its size (the size threshold still
            // applies to apps not switched over). Set by `signing:convert-delta`; a whole build made after it
            // is the runner's fallback (pack not worth it), not one to replace.
            $table->timestamp('delta_builds_at')->nullable()->after('is_storefront');
        });
    }

    public function down(): void
    {
        Schema::table('apps', function (Blueprint $table) {
            $table->dropColumn('delta_builds_at');
        });
    }
};
