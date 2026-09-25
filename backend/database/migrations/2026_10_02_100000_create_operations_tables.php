<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hardening and operations (IMPLEMENTATION_PLAN Phase 8).
     */
    public function up(): void
    {
        // Support requests from the portal (P8-WEB-01).
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('topic', 32);
            $table->text('message');
            // The request ID the customer saw on an error screen, if any.
            $table->string('reference_request_id', 64)->nullable();
            $table->string('status', 16)->default('OPEN');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // Aggregated metrics from FULL_PLAN §14, written by the scheduler (P8-OPS-02).
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->json('labels')->nullable();
            $table->double('value');
            $table->timestamp('captured_at');

            $table->index(['name', 'captured_at']);
        });

        // Data export / deletion workflow (P8-SEC-02).
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deletion_requested_at')->nullable()->after('status');
            $table->timestamp('erased_at')->nullable()->after('deletion_requested_at');
        });

        // UDID retention: the encrypted UDID is removed; the HMAC blind index stays for uniqueness.
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('udid_purged_at')->nullable()->after('storefront_claimed_at');
        });

        Schema::table('signed_builds', function (Blueprint $table) {
            $table->timestamp('purged_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('signed_builds', fn (Blueprint $table) => $table->dropColumn('purged_at'));
        Schema::table('devices', fn (Blueprint $table) => $table->dropColumn('udid_purged_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['deletion_requested_at', 'erased_at']));
        Schema::dropIfExists('metric_snapshots');
        Schema::dropIfExists('support_tickets');
    }
};
