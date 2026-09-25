<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Signing and installation (IMPLEMENTATION_PLAN Phase 6, §5.6).
     * Certificate private keys never reach this database: they live only in
     * the runner's Keychain (D8); rows here are metadata.
     */
    public function up(): void
    {
        Schema::create('runners', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('key_id', 64)->unique();
            $table->string('name');
            // HMAC secret for the worker API (IMPLEMENTATION_PLAN D9); Laravel-encrypted.
            $table->text('secret_encrypted');
            $table->string('status', 16)->default('ACTIVE');
            $table->string('version', 32)->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->json('identities')->nullable();
            $table->timestamps();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('apple_team_id')->constrained()->restrictOnDelete();
            $table->char('sha1_fingerprint', 40)->unique();
            $table->string('serial_number', 64);
            $table->string('common_name');
            $table->string('apple_certificate_id', 64)->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('expires_at')->nullable();
            // Which runner reported holding the private key, and when.
            $table->foreignId('runner_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['apple_team_id', 'status']);
        });

        Schema::create('signing_profiles', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('apple_team_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->string('bundle_identifier');
            $table->string('apple_profile_id', 64);
            $table->string('uuid', 64);
            $table->string('name');
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('expires_at')->nullable();
            // The .mobileprovision lists device UDIDs, so it is stored encrypted.
            $table->longText('content_encrypted');
            $table->timestamps();

            // D10: one ad hoc profile per (team, bundle ID, device).
            $table->unique(['apple_team_id', 'bundle_identifier', 'device_id'], 'signing_profiles_team_bundle_device_unique');
        });

        Schema::create('signed_builds', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('artifact_id')->constrained('app_artifacts')->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('signing_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('certificate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('SIGNING_PENDING');
            $table->string('status_reason', 64)->nullable();
            // Derived artifact: new hash, parent reference (FULL_PLAN §5.3).
            $table->char('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('storage_path')->nullable();
            $table->json('signing_report')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['artifact_id', 'device_id', 'status']);
        });

        Schema::create('installations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            $table->foreignId('app_id')->constrained('apps')->restrictOnDelete();
            $table->foreignId('artifact_id')->constrained('app_artifacts')->restrictOnDelete();
            $table->foreignId('signed_build_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('PREPARING');
            $table->string('status_reason', 64)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'app_id', 'status']);
            $table->index('status');
        });

        Schema::create('install_authorizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('manifest_fetched_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index('expires_at');
        });

        Schema::create('installation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('installation_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['installation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_events');
        Schema::dropIfExists('install_authorizations');
        Schema::dropIfExists('installations');
        Schema::dropIfExists('signed_builds');
        Schema::dropIfExists('signing_profiles');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('runners');
    }
};
