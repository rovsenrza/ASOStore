<?php

namespace App\Enums;

/**
 * Apple product families; quotas are counted per family and membership year (FULL_PLAN §1.3).
 */
enum DeviceFamily: string
{
    case Iphone = 'IPHONE';
    case Ipad = 'IPAD';
    case Ipod = 'IPOD';
    case Unknown = 'UNKNOWN';

    /**
     * Classify from the PRODUCT attribute iOS reports during enrollment, e.g. "iPhone15,2".
     */
    public static function fromProduct(?string $product): self
    {
        return match (true) {
            $product === null => self::Unknown,
            str_starts_with($product, 'iPhone') => self::Iphone,
            str_starts_with($product, 'iPad') => self::Ipad,
            str_starts_with($product, 'iPod') => self::Ipod,
            default => self::Unknown,
        };
    }
}
