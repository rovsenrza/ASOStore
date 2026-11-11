<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which site accounts a Telegram customer may extend from the bot, beyond those the bot can
     * tell from a redeemed code: confirmed by a code sent to the account's email, or opened from
     * the signed-in site. And when a buyer was reminded about a paid code nobody redeemed.
     */
    public function up(): void
    {
        Schema::create('telegram_account_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('telegram_store_customers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // email_code | site
            $table->string('via', 16);
            $table->timestamps();
            $table->unique(['customer_id', 'user_id']);
        });

        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->timestamp('code_reminded_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->dropColumn('code_reminded_at');
        });
        Schema::dropIfExists('telegram_account_links');
    }
};
