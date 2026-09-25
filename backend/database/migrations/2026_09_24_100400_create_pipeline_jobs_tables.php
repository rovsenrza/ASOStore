<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Domain jobs visible to operators (FULL_PLAN §8.3). Named pipeline_jobs so
     * Laravel's own queue table keeps the name "jobs" (IMPLEMENTATION_PLAN G4).
     */
    public function up(): void
    {
        Schema::create('pipeline_jobs', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('type', 64);
            $table->string('status', 24)->default('QUEUED');
            $table->string('idempotency_key')->unique();
            $table->unsignedSmallInteger('attempt')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('correlation_id', 64);
            $table->nullableMorphs('subject');
            // Never store secrets here; payloads are visible to operators.
            $table->json('payload')->nullable();
            $table->string('lease_owner', 64)->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_message_redacted')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['type', 'status']);
            $table->index('correlation_id');
        });

        Schema::create('pipeline_job_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pipeline_job_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('worker', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->string('result_code', 64)->nullable();
            $table->string('error_class')->nullable();
            $table->text('error_message_redacted')->nullable();
            $table->timestamps();

            $table->unique(['pipeline_job_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_job_attempts');
        Schema::dropIfExists('pipeline_jobs');
    }
};
