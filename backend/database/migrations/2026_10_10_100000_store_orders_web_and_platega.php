<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store orders paid on the website belong to an account instead of a Telegram chat,
 * and orders paid through Platega remember the provider's transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_user_id')->nullable()->change();
            $table->unsignedBigInteger('chat_id')->nullable()->change();
            $table->foreignId('user_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            // The provider's transaction for the current payment page; once paid, the one that paid.
            $table->string('payment_reference', 64)->nullable()->after('payment_provider')->index();
            $table->text('payment_url')->nullable()->after('payment_reference');
            $table->unsignedInteger('payment_amount_rub')->nullable()->after('payment_url');
            $table->timestamp('payment_expires_at')->nullable()->after('payment_amount_rub');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['payment_reference']);
            $table->dropColumn(['payment_reference', 'payment_url', 'payment_amount_rub', 'payment_expires_at']);
        });
    }
};
