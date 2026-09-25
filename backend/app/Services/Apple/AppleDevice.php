<?php

namespace App\Services\Apple;

use App\Enums\AppleDeviceStatus;

/**
 * A device as the Apple side reports it. Keeps Apple's response shape out of
 * the rest of the app (FULL_PLAN §8.2).
 */
final readonly class AppleDevice
{
    public function __construct(
        public string $id,
        public string $udid,
        public AppleDeviceStatus $status,
    ) {}
}
