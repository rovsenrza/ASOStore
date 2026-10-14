<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What the compat shim reports from inside re-signed apps: a launch that held,
        // a crash, or an exit right after launch. Technical data only, no personal data.
        Schema::create('app_launch_reports', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('bundle_identifier', 190)->index();
            $table->string('original_bundle_identifier', 190)->nullable();
            $table->foreignId('app_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('apple_team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 16)->nullable();
            $table->string('device_model', 32)->nullable();
            $table->string('shim_version', 16)->nullable();
            $table->string('detail', 500)->nullable();
            $table->text('backtrace')->nullable();
            $table->timestamp('happened_at')->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['app_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_launch_reports');
    }
};
