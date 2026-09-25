<?php

namespace App\Services\Signing;

use CFPropertyList\CFPropertyList;
use DateTimeInterface;
use Throwable;

/**
 * Reads the XML plist inside a .mobileprovision (a CMS envelope). The CMS
 * signature itself is Apple's; the runner's codesign verification covers it.
 */
final class ProvisioningProfile
{
    /**
     * @return array{uuid: string|null, team_identifier: string|null, devices: list<string>, expires_at: int|null, entitlements: array<string, mixed>}|null
     */
    public static function parse(string $bytes): ?array
    {
        $start = strpos($bytes, '<?xml');
        $end = strpos($bytes, '</plist>');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        try {
            $plist = new CFPropertyList;
            $plist->parse(substr($bytes, $start, $end - $start + strlen('</plist>')));
            $values = $plist->toArray();
        } catch (Throwable) {
            return null;
        }
        if (! is_array($values)) {
            return null;
        }

        $expires = $values['ExpirationDate'] ?? null;

        return [
            'uuid' => is_string($values['UUID'] ?? null) ? $values['UUID'] : null,
            'team_identifier' => is_string($values['TeamIdentifier'][0] ?? null) ? $values['TeamIdentifier'][0] : null,
            'devices' => array_values(array_map('strval', (array) ($values['ProvisionedDevices'] ?? []))),
            'expires_at' => $expires instanceof DateTimeInterface ? $expires->getTimestamp() : (is_numeric($expires) ? (int) $expires : null),
            'entitlements' => (array) ($values['Entitlements'] ?? []),
        ];
    }
}
