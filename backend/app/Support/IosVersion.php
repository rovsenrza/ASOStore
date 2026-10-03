<?php

namespace App\Support;

/**
 * The iOS version of an enrolled device. Apple's profile service answers VERSION with the build
 * number (23G83), not the version (26.6), and the install check compares versions.
 */
final class IosVersion
{
    /** "26.6" from a version or an iOS build number; null when the value is neither. */
    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);
        if (preg_match('/^\d{1,3}(\.\d{1,3}){0,2}$/', $value) === 1) {
            return $value;
        }
        // A build number starts with its release train and a letter for the minor version (A = .0).
        // Trains up to 22 are iOS 18 and older (train − 4); from 23 on, iOS is numbered by year (26, 27…).
        if (preg_match('/^(\d{2})([A-Z])\d{1,5}[a-z]?$/', $value, $match) === 1) {
            $train = (int) $match[1];

            return ($train >= 23 ? $train + 3 : $train - 4).'.'.(ord($match[2]) - ord('A'));
        }

        return null;
    }
}
