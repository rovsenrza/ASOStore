<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only audit trail (FULL_PLAN §7, §13). No foreign keys on purpose:
     * audit rows must outlive the users and records they describe.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->timestamp('occurred_at', 6);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('action', 96);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->string('ip', 45)->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'occurred_at']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
