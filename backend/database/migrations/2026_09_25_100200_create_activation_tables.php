<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activation codes are shown once at creation and stored only as a hash
     * (FULL_PLAN §7: unique activation code hash). Redeeming one creates a
     * subscription placeholder until payments exist.
     */
    public function up(): void
    {
        Schema::create('activation_codes', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('batch_id')->index();
            $table->char('code_hash', 64)->unique();
            $table->string('code_hint', 8);
            $table->string('status', 16)->default('ISSUED');
            $table->string('plan', 32)->default('standard');
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('activation_code_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('plan', 32);
            $table->string('status', 16)->default('ACTIVE');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('activation_codes');
    }
};
