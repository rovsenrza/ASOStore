<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device enrollment (IMPLEMENTATION_PLAN §5.5) and Apple-side registration.
     */
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('enrolled_at')->nullable()->after('name');
            $table->timestamp('storefront_claimed_at')->nullable()->after('enrolled_at');
        });

        // Single-use token embedded in the .mobileconfig; only its hash is stored.
        Schema::create('enrollment_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('challenge_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });

        Schema::create('device_registrations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('apple_team_id')->constrained()->restrictOnDelete();
            $table->foreignId('membership_year_id')->constrained()->restrictOnDelete();
            // Copied from the device so the unique index below can use it (IMPLEMENTATION_PLAN G5).
            $table->char('udid_hash', 64);
            $table->string('device_family', 16);
            $table->string('status', 24)->default('ENROLLED');
            $table->string('status_reason', 64)->nullable();
            $table->string('apple_device_id', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('eligible_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // FULL_PLAN §7: unique (apple_team_id, device_udid, membership_year_id).
            $table->unique(['apple_team_id', 'udid_hash', 'membership_year_id'], 'device_registrations_team_udid_year_unique');
            $table->index(['apple_team_id', 'device_family', 'status'], 'device_registrations_team_family_status_index');
            $table->index('status');
        });

        // One-time code that binds the native app to an enrolled device (IMPLEMENTATION_PLAN G8).
        Schema::create('storefront_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('redeemed_at')->nullable();
            $table->string('redeemed_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
        Schema::dropIfExists('storefront_claims');
        Schema::dropIfExists('device_registrations');
        Schema::dropIfExists('enrollment_challenges');
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['enrolled_at', 'storefront_claimed_at']);
        });
    }
};
