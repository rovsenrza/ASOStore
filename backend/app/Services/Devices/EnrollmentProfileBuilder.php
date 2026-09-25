<?php

namespace App\Services\Devices;

use CFPropertyList\CFPropertyList;
use CFPropertyList\CFTypeDetector;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds the Profile Service .mobileconfig that asks iOS for the device's
 * UDID, model and iOS version (IMPLEMENTATION_PLAN §5.5). Nothing else is
 * requested: no serial number, IMEI or ICCID.
 */
class EnrollmentProfileBuilder
{
    public const DEVICE_ATTRIBUTES = ['UDID', 'PRODUCT', 'VERSION'];

    public function build(string $callbackUrl, string $challenge): string
    {
        $brand = (string) config('storefront.brand');
        $profile = [
            'PayloadContent' => [
                'URL' => $callbackUrl,
                'DeviceAttributes' => self::DEVICE_ATTRIBUTES,
                'Challenge' => $challenge,
            ],
            'PayloadOrganization' => $brand,
            'PayloadDisplayName' => $brand.' — регистрация устройства',
            'PayloadDescription' => 'Передаёт идентификатор, модель и версию iOS этого устройства, чтобы подготовить для него приложения. Профиль не остаётся на устройстве.',
            'PayloadVersion' => 1,
            'PayloadUUID' => strtoupper((string) Str::uuid()),
            'PayloadIdentifier' => config('storefront.enrollment.profile_identifier'),
            'PayloadType' => 'Profile Service',
        ];

        $plist = new CFPropertyList;
        $plist->add((new CFTypeDetector)->toCFType($profile));

        return $plist->toXML(true);
    }

    /**
     * Signs the profile with the site's TLS certificate so iOS shows it as
     * verified. Returns null when no signing certificate is configured.
     */
    public function sign(string $xml): ?string
    {
        $certificate = config('storefront.enrollment.signing_certificate');
        $key = config('storefront.enrollment.signing_key');
        if (! $certificate || ! $key) {
            return null;
        }

        $in = tempnam(sys_get_temp_dir(), 'profile-in');
        $out = tempnam(sys_get_temp_dir(), 'profile-out');

        try {
            file_put_contents($in, $xml);
            $signed = openssl_cms_sign(
                $in,
                $out,
                'file://'.$certificate,
                'file://'.$key,
                [],
                OPENSSL_CMS_BINARY,
                OPENSSL_ENCODING_DER,
                config('storefront.enrollment.signing_chain') ?: null,
            );
            if (! $signed) {
                throw new RuntimeException('Signing the enrollment profile failed: '.openssl_error_string());
            }

            return (string) file_get_contents($out);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }
}
