<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A cleaned copy (tools/ipa-cleaner) is a new artifact that names its source and
        // keeps the cleaner's report: what was removed, hashes, verification.
        Schema::table('app_artifacts', function (Blueprint $table) {
            $table->foreignId('derived_from_artifact_id')->nullable()->after('app_version_id')->constrained('app_artifacts')->restrictOnDelete();
            $table->json('cleaning_report')->nullable()->after('inspection');
        });
    }

    public function down(): void
    {
        Schema::table('app_artifacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('derived_from_artifact_id');
            $table->dropColumn('cleaning_report');
        });
    }
};
