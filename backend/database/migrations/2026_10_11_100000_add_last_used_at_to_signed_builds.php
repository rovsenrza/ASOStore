<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the build was last asked for or downloaded: idle signed builds are
        // removed from object storage (StorageJanitor) and signed again on demand.
        Schema::table('signed_builds', function (Blueprint $table) {
            $table->timestamp('last_used_at')->nullable()->after('expires_at');
            $table->index(['status', 'purged_at']);
        });

        DB::table('signed_builds')->update(['last_used_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('signed_builds', function (Blueprint $table) {
            $table->dropIndex(['status', 'purged_at']);
            $table->dropColumn('last_used_at');
        });
    }
};
