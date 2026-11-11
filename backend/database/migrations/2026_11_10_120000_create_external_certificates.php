<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instant certificates: a second registration method. A device gets a signing certificate and
 * profile bought from a reseller API (InstantCertificates) instead of a slot on one of our Apple
 * teams. Its Apple team is recorded as an EXTERNAL team (no ASC key, never routed to), and every
 * app is signed for it with that one profile by the dedicated external runner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apple_teams', function (Blueprint $table) {
            // The profile's App ID without the team prefix; apps are signed as <base>.a<listing id>.
            $table->string('external_bundle_base', 160)->nullable()->after('routing_rule');
        });
        Schema::table('users', function (Blueprint $table) {
            // null = our Apple teams (the usual 24–72 h registration); INSTANT = instant certificate.
            $table->string('registration_method', 16)->nullable()->after('status');
        });
        Schema::create('external_certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apple_team_id')->constrained('apple_teams');
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->foreignId('certificate_id')->nullable()->constrained('certificates')->nullOnDelete();
            $table->string('udid_hash', 64)->index();
            $table->string('provider', 32);
            $table->string('provider_certificate_id', 64);
            $table->string('plan', 64)->nullable();
            $table->string('common_name')->nullable();
            $table->string('sha1_fingerprint', 40);
            $table->string('profile_uuid', 64);
            $table->text('profile_encrypted');
            $table->timestamp('profile_expires_at')->nullable();
            $table->timestamp('warranty_until')->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_certificate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_certificates');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('registration_method'));
        Schema::table('apple_teams', fn (Blueprint $table) => $table->dropColumn('external_bundle_base'));
    }
};
