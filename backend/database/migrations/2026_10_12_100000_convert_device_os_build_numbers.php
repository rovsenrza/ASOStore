<?php

use App\Support\IosVersion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Enrollment stored the iOS build number (23G83) as the device's version; the install check
        // compares versions (26.6). Versions and unknown values stay as they are.
        DB::table('devices')->whereNotNull('os_version')->orderBy('id')->each(function (object $device) {
            $version = IosVersion::normalize($device->os_version);
            if ($version !== null && $version !== $device->os_version) {
                DB::table('devices')->where('id', $device->id)->update(['os_version' => $version]);
            }
        });
    }

    public function down(): void
    {
        // The build numbers are not kept, so there is nothing to restore.
    }
};
