<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_registrations', function (Blueprint $table) {
            // The week of free time given because registering took too long (once per registration).
            $table->timestamp('delay_gift_at')->nullable()->after('eligible_at');
            // When the wait was assessed after Apple enabled the device, and the minutes it added (0: no delay).
            $table->timestamp('delay_assessed_at')->nullable()->after('delay_gift_at');
            $table->unsignedInteger('delay_compensation_minutes')->nullable()->after('delay_assessed_at');
        });
    }

    public function down(): void
    {
        Schema::table('device_registrations', function (Blueprint $table) {
            $table->dropColumn(['delay_gift_at', 'delay_assessed_at', 'delay_compensation_minutes']);
        });
    }
};
