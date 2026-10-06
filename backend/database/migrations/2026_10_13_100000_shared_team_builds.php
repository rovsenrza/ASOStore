<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Builds shared by an Apple team: one ad hoc profile lists every eligible device of the
 * team (its "cohort"), and one signed build of an artifact serves all of them, so an
 * install reuses a ready build instead of signing for each device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signing_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable()->change();
            // Shared profiles: SHA-1 of the sorted Apple device IDs, and the local devices listed.
            $table->char('cohort', 40)->nullable()->after('device_id');
            $table->json('device_ids')->nullable()->after('cohort');
            $table->unique(['apple_team_id', 'bundle_identifier', 'cohort'], 'signing_profiles_team_bundle_cohort_unique');
        });

        Schema::table('signed_builds', function (Blueprint $table) {
            $table->unsignedBigInteger('device_id')->nullable()->change();
            $table->foreignId('apple_team_id')->nullable()->after('device_id')->constrained()->restrictOnDelete();
            $table->index(['artifact_id', 'apple_team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('signed_builds', function (Blueprint $table) {
            $table->dropIndex(['artifact_id', 'apple_team_id', 'status']);
            $table->dropConstrainedForeignId('apple_team_id');
        });
        Schema::table('signing_profiles', function (Blueprint $table) {
            $table->dropUnique('signing_profiles_team_bundle_cohort_unique');
            $table->dropColumn(['cohort', 'device_ids']);
        });
    }
};
