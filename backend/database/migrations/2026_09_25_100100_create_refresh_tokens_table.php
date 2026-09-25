<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rotating refresh tokens for the native app (IMPLEMENTATION_PLAN D3, G10).
     * Tokens in one family descend from the same sign-in; reusing a spent token
     * revokes the whole family.
     */
    public function up(): void
    {
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->ulid('family_id');
            $table->char('token_hash', 64)->unique();
            $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->foreignId('replaced_by_id')->nullable()->constrained('refresh_tokens')->nullOnDelete();
            $table->string('device_name', 100)->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['family_id', 'revoked_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};
