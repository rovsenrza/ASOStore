<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Human provenance review (FULL_PLAN §5.1.1, IMPLEMENTATION_PLAN P5-BE-03).
     * Reviews are append-only decisions; documents are optional evidence files
     * kept on the private artifacts disk.
     */
    public function up(): void
    {
        Schema::create('artifact_reviews', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('artifact_id')->constrained('app_artifacts')->restrictOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->restrictOnDelete();
            // APPROVED | REJECTED | RELEASED (from quarantine)
            $table->string('decision', 16);
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->text('reason')->nullable();
            $table->string('checklist_version', 32)->nullable();
            $table->json('checklist')->nullable();
            $table->boolean('scan_result_acknowledged')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['artifact_id', 'id']);
        });

        Schema::create('provenance_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('artifact_id')->constrained('app_artifacts')->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('storage_path');
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index('artifact_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provenance_documents');
        Schema::dropIfExists('artifact_reviews');
    }
};
