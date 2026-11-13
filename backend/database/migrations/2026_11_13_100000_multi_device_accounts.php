<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Several iPhones per account, each paid for separately.
 *
 * - A subscription belongs to a device (subscriptions.device_id). A device bought for an
 *   account but not connected yet is a "pending" device: a devices row without a UDID that
 *   the next new iPhone enrolling on that account takes over.
 * - The registration method (instant certificate or our Apple teams) is per device.
 * - A store order can extend a given device (renew_device_id) or add one (add_device).
 *
 * Existing accounts (one iPhone each): their subscriptions move onto that iPhone; an account
 * that paid but never connected an iPhone gets a pending device holding its subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->text('udid_encrypted')->nullable()->change();
            $table->char('udid_hash', 64)->nullable()->change();
            $table->string('udid_hint', 8)->nullable()->change();
            $table->string('registration_method', 16)->nullable()->after('device_family');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['device_id', 'status', 'ends_at']);
        });

        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->foreignId('renew_device_id')->nullable()->after('renew_user_id')->constrained('devices')->nullOnDelete();
            $table->boolean('add_device')->default(false)->after('renew_device_id');
        });

        $this->moveSubscriptionsOntoDevices();
    }

    public function down(): void
    {
        Schema::table('telegram_store_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('renew_device_id');
            $table->dropColumn('add_device');
        });
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['device_id', 'status', 'ends_at']);
            $table->dropConstrainedForeignId('device_id');
        });
        DB::table('devices')->whereNull('udid_hash')->delete();
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('registration_method');
        });
    }

    private function moveSubscriptionsOntoDevices(): void
    {
        $now = now();
        $userIds = DB::table('subscriptions')->whereNull('device_id')->distinct()->pluck('user_id');
        foreach ($userIds->chunk(200) as $chunk) {
            $devices = DB::table('devices')->whereIn('user_id', $chunk)->whereNotNull('udid_hash')
                ->orderBy('id')->get(['id', 'user_id'])->groupBy('user_id');
            $methods = DB::table('users')->whereIn('id', $chunk)->pluck('registration_method', 'id');

            foreach ($chunk as $userId) {
                // One iPhone per account until now; should there be more, the first one enrolled
                // keeps the access the account paid for.
                $deviceId = $devices->get($userId)?->first()?->id;
                if ($deviceId === null) {
                    $deviceId = DB::table('devices')->insertGetId([
                        'public_id' => strtolower((string) Str::ulid()),
                        'user_id' => $userId,
                        'device_family' => 'UNKNOWN',
                        'registration_method' => $methods[$userId] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                DB::table('subscriptions')->where('user_id', $userId)->whereNull('device_id')->update(['device_id' => $deviceId]);
            }
        }

        // The instant method chosen for the account becomes its iPhones' method.
        DB::table('devices')->whereNull('registration_method')
            ->whereIn('user_id', DB::table('users')->whereNotNull('registration_method')->select('id'))
            ->update(['registration_method' => DB::raw('(select registration_method from users where users.id = devices.user_id)')]);
    }
};
