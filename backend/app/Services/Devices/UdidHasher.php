<?php

namespace App\Services\Devices;

use RuntimeException;

/**
 * HMAC blind index for UDIDs (IMPLEMENTATION_PLAN G5). Encrypted values are
 * non-deterministic, so uniqueness and lookups use this hash instead.
 */
class UdidHasher
{
    private string $key;

    public function __construct(?string $base64Key)
    {
        $key = $base64Key === null ? false : base64_decode($base64Key, true);

        if ($key === false || strlen($key) < 32) {
            throw new RuntimeException('STOREFRONT_UDID_HMAC_KEY must be a base64-encoded key of at least 32 bytes.');
        }

        $this->key = $key;
    }

    public static function normalize(string $udid): string
    {
        return strtoupper(trim($udid));
    }

    public function hash(string $udid): string
    {
        return hash_hmac('sha256', self::normalize($udid), $this->key);
    }
}
