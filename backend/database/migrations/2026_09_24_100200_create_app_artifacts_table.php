<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Original uploaded IPAs. The file-identity and declaration columns are
     * immutable (enforced by triggers in a later migration); status and
     * inspection results change as the artifact moves through its lifecycle.
     */
    public function up(): void
    {
        Schema::create('app_artifacts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('app_id')->constrained('apps')->restrictOnDelete();
            $table->foreignId('app_version_id')->nullable()->constrained('app_versions')->restrictOnDelete();

            // Immutable file identity.
            $table->char('sha256', 64)->unique();
            $table->unsignedBigInteger('size_bytes');
            $table->string('storage_disk', 32);
            $table->string('storage_path');
            $table->string('original_filename');

            // Immutable provenance declaration (FULL_PLAN §1.2, §5.1.1).
            $table->string('source_type', 40);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('declaration_version', 32);
            $table->timestamp('declaration_accepted_at');
            $table->string('declaration_ip', 45)->nullable();

            // Lifecycle.
            $table->string('status', 32)->default('UPLOADED');
            $table->string('status_reason')->nullable();

            // Filled by inspection (FULL_PLAN §5.3).
            $table->string('bundle_identifier')->nullable();
            $table->string('version', 32)->nullable();
            $table->string('build_number', 32)->nullable();
            $table->string('min_ios_version', 16)->nullable();
            $table->json('inspection')->nullable();

            // Set when retention cleanup removes the file; the record itself is kept.
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['app_id', 'status']);
            $table->index('bundle_identifier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_artifacts');
    }
};
