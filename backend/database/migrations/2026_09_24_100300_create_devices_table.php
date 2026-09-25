<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * UDIDs are stored encrypted, with an HMAC blind index for uniqueness and
     * lookup (IMPLEMENTATION_PLAN G5) and a short hint for masked display.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->text('udid_encrypted');
            $table->char('udid_hash', 64);
            $table->string('udid_hint', 8);
            $table->string('product', 32)->nullable();
            $table->string('device_family', 16)->default('UNKNOWN');
            $table->string('os_version', 16)->nullable();
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'udid_hash']);
            $table->index('udid_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
