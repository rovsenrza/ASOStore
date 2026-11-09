<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A "warm-up" team takes only the first device of a new one-month customer (AppleTeam::RULE_NEW_MONTHLY);
// how many it takes is its team_quotas.limit_count.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apple_teams', function (Blueprint $table) {
            $table->string('routing_rule', 32)->nullable()->after('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('apple_teams', function (Blueprint $table) {
            $table->dropColumn('routing_rule');
        });
    }
};
