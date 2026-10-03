<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signed_builds', function (Blueprint $table) {
            // The one-time storefront login code embedded in this build's Info.plist (encrypted at
            // rest). Only set for the storefront app, so its first launch signs the customer in.
            $table->text('bootstrap_claim_encrypted')->nullable()->after('signing_report');
        });
    }

    public function down(): void
    {
        Schema::table('signed_builds', function (Blueprint $table) {
            $table->dropColumn('bootstrap_claim_encrypted');
        });
    }
};
