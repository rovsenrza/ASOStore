<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apple Developer teams (FULL_PLAN §6.1). Phase 3 uses a single team;
     * quotas, eligibility and multi-team selection arrive in Phase 7.
     * No secrets here: credentials hold only a reference to the secret store.
     */
    public function up(): void
    {
        Schema::create('apple_teams', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('apple_team_id', 20)->unique();
            $table->string('name');
            $table->string('status', 24)->default('PENDING_VERIFICATION');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('membership_expires_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('apple_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apple_team_id')->constrained()->restrictOnDelete();
            $table->string('issuer_id', 64);
            $table->string('key_id', 20);
            // e.g. "encrypted-file:secrets/apple/ABC123.p8.enc" — never the key itself.
            $table->string('vault_reference');
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();

            $table->unique(['apple_team_id', 'key_id']);
        });

        Schema::create('membership_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apple_team_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamps();

            $table->index(['apple_team_id', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_years');
        Schema::dropIfExists('apple_credentials');
        Schema::dropIfExists('apple_teams');
    }
};
