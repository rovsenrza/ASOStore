<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A bot order bought to extend this account: paying it redeems the code there. */
    public function up(): void
    {
        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->foreignId('renew_user_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renew_user_id');
        });
    }
};
