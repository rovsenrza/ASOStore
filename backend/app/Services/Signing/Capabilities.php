<?php

namespace App\Services\Signing;

/**
 * App Store Connect capabilities an app's entitlements need on its bundle ID, so the
 * ad hoc profile Apple generates carries them. Only capabilities that need no extra
 * settings are enabled automatically; anything else is left to an operator.
 */
final class Capabilities
{
    /** Entitlement key → bundleIdCapabilities capabilityType. */
    private const MAP = [
        'com.apple.developer.networking.networkextension' => 'NETWORK_EXTENSIONS',
        'com.apple.developer.networking.vpn.api' => 'PERSONAL_VPN',
        'com.apple.security.application-groups' => 'APP_GROUPS',
        'aps-environment' => 'PUSH_NOTIFICATIONS',
        'com.apple.developer.associated-domains' => 'ASSOCIATED_DOMAINS',
        'com.apple.developer.networking.wifi-info' => 'ACCESS_WIFI_INFORMATION',
        'com.apple.developer.networking.multipath' => 'MULTIPATH',
        'com.apple.developer.networking.HotspotConfiguration' => 'HOT_SPOT',
        'com.apple.developer.nfc.readersession.formats' => 'NFC_TAG_READING',
        'com.apple.developer.siri' => 'SIRIKIT',
        'com.apple.developer.healthkit' => 'HEALTHKIT',
        'com.apple.developer.homekit' => 'HOMEKIT',
        'com.apple.developer.authentication-services.autofill-credential-provider' => 'AUTOFILL_CREDENTIAL_PROVIDER',
    ];

    /**
     * @param  array<string, mixed>  $entitlements
     * @return list<string>
     */
    public static function fromEntitlements(array $entitlements): array
    {
        $types = [];
        foreach (self::MAP as $key => $type) {
            if (array_key_exists($key, $entitlements)) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
