<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stored responses for Idempotency-Key replays (IMPLEMENTATION_PLAN §5.4).
     * scope is "user:{id}" or "worker:{id}", so it is never NULL inside the unique index.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            $table->string('route', 128);
            $table->string('key', 128);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['scope', 'route', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
