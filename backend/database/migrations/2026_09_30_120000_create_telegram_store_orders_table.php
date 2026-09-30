<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_store_orders', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('telegram_user_id')->index();
            $table->unsignedBigInteger('chat_id')->index();
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('plan', 32)->default('standard');
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedInteger('price_rub');
            $table->string('status', 16)->default('PENDING');
            $table->string('payment_method', 24)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('paid_at')->nullable();
            // Only the purchaser's private chat receives this recoverable code.
            $table->text('activation_code')->nullable();
            $table->timestamps();

            $table->index(['telegram_user_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_store_orders');
    }
};
