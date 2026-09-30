<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_store_promo_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('type', 8);
            $table->unsignedInteger('value');
            $table->unsignedInteger('max_uses')->nullable();
            // Plan keys the code applies to; null means every plan.
            $table->json('plan_keys')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->foreignId('promo_code_id')->nullable()->after('plan_key')->constrained('telegram_store_promo_codes')->nullOnDelete();
            $table->unsignedInteger('discount_rub')->default(0)->after('price_rub');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promo_code_id');
            $table->dropColumn('discount_rub');
        });
        Schema::dropIfExists('telegram_store_promo_codes');
    }
};
