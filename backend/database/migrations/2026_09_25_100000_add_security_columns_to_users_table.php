<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff two-factor authentication (IMPLEMENTATION_PLAN D4). The secret is
     * stored encrypted; totp_last_step blocks reuse of an accepted code.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('totp_secret')->nullable()->after('password');
            $table->timestamp('totp_confirmed_at')->nullable()->after('totp_secret');
            $table->unsignedBigInteger('totp_last_step')->nullable()->after('totp_confirmed_at');
            $table->timestamp('last_login_at')->nullable()->after('locale');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_last_step', 'last_login_at']);
        });
    }
};
