<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quota and team operations (FULL_PLAN §6, IMPLEMENTATION_PLAN Phase 7).
     */
    public function up(): void
    {
        // One lockable row per (team, membership year, family): reservations take
        // SELECT … FOR UPDATE on it (P7-BE-02). remaining is computed, never stored.
        Schema::create('team_quotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apple_team_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_year_id')->constrained()->restrictOnDelete();
            $table->string('device_family', 16);
            $table->unsignedInteger('limit_count');
            // What Apple listed at the last reconciliation; never used to correct local state.
            $table->unsignedInteger('apple_registered_count')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['apple_team_id', 'membership_year_id', 'device_family'], 'team_quotas_team_year_family_unique');
        });

        Schema::create('quota_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_quota_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_registration_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('RESERVED');
            $table->timestamp('expires_at');
            $table->string('released_reason', 64)->nullable();
            $table->timestamps();

            $table->index(['team_quota_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        // Evidence that a team may distribute a bundle ID (P7-BE-03).
        Schema::create('team_app_eligibilities', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('apple_team_id')->constrained()->restrictOnDelete();
            $table->string('bundle_identifier');
            $table->text('evidence');
            $table->string('status', 16)->default('APPROVED');
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->unique(['apple_team_id', 'bundle_identifier']);
            $table->index('bundle_identifier');
        });

        // A proposed move of a blocked device to another eligible team; nothing
        // happens until an admin approves it (FULL_PLAN §12 "Handle quota exhaustion").
        Schema::create('team_assignments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('blocked_registration_id')->constrained('device_registrations')->restrictOnDelete();
            $table->foreignId('apple_team_id')->constrained()->restrictOnDelete();
            $table->string('status', 16)->default('PENDING');
            // Why this team was proposed (selection explanation, FULL_PLAN §6.2 step 5).
            $table->text('selection_reason');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('registration_id')->nullable()->constrained('device_registrations')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_assignments');
        Schema::dropIfExists('team_app_eligibilities');
        Schema::dropIfExists('quota_reservations');
        Schema::dropIfExists('team_quotas');
    }
};
