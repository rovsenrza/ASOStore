<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_store_customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_user_id')->unique();
            $table->unsignedBigInteger('chat_id');
            $table->string('username')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('referral_code', 16)->unique();
            $table->foreignId('referrer_id')->nullable()->constrained('telegram_store_customers')->nullOnDelete();
            $table->unsignedInteger('balance_rub')->default(0);
            $table->unsignedInteger('referral_earned_rub')->default(0);
            // Set when Telegram answers 403: the user blocked the bot.
            $table->timestamp('blocked_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained('telegram_store_customers')->nullOnDelete();
            $table->string('plan_key', 16)->nullable()->after('plan');
            $table->unsignedInteger('balance_used_rub')->default(0)->after('price_rub');
            $table->unsignedInteger('amount_due_rub')->default(0)->after('balance_used_rub');
            $table->unsignedInteger('referral_bonus_rub')->default(0)->after('amount_due_rub');
            $table->string('payment_provider', 16)->nullable()->after('payment_method');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('paid_at');

            $table->index(['status', 'paid_at']);
        });

        Schema::create('telegram_store_balance_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('telegram_store_customers')->cascadeOnDelete();
            $table->integer('amount_rub');
            $table->string('type', 24);
            $table->foreignId('order_id')->nullable()->constrained('telegram_store_orders')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['customer_id', 'created_at']);
            $table->index(['type', 'created_at']);
        });

        Schema::create('telegram_store_settings', function (Blueprint $table) {
            $table->string('key', 64)->primary();
            $table->json('value');
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('telegram_store_broadcasts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('admin_chat_id');
            $table->unsignedBigInteger('from_chat_id');
            $table->unsignedBigInteger('message_id');
            $table->string('status', 16)->default('QUEUED');
            // Customers are walked in id order so a re-dispatched slice resumes where the last stopped.
            $table->unsignedBigInteger('cursor_id')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_store_broadcasts');
        Schema::dropIfExists('telegram_store_settings');
        Schema::dropIfExists('telegram_store_balance_transactions');
        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropIndex(['status', 'paid_at']);
            $table->dropColumn(['plan_key', 'balance_used_rub', 'amount_due_rub', 'referral_bonus_rub', 'payment_provider', 'reviewed_by']);
        });
        Schema::dropIfExists('telegram_store_customers');
    }
};
