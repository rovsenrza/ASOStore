<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('app_id')->constrained('apps')->restrictOnDelete();
            $table->foreignId('app_version_id')->nullable()->constrained('app_versions')->restrictOnDelete();
            $table->foreignId('artifact_id')->nullable()->constrained('app_artifacts')->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_filename');
            $table->unsignedBigInteger('expected_size');
            $table->char('expected_sha256', 64)->nullable();
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('chunk_count');
            $table->string('source_type', 40);
            $table->string('declaration_version', 32);
            $table->timestamp('declaration_accepted_at');
            $table->string('declaration_ip', 45)->nullable();
            $table->string('status', 24)->default('OPEN');
            $table->string('failure_reason')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('upload_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_session_id')->constrained('upload_sessions')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('storage_path');
            $table->timestamps();

            $table->unique(['upload_session_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_chunks');
        Schema::dropIfExists('upload_sessions');
    }
};
