<?php

namespace App\Services\Devices;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use CFPropertyList\CFPropertyList;
use Throwable;

/**
 * Reads the answer iOS posts back to the Profile Service URL: a CMS (PKCS#7)
 * message, DER-encoded, signed by the device, wrapping a plist with the
 * requested attributes and the challenge.
 *
 * The signature is always checked. When an Apple device CA file is
 * configured, the signer must also chain to it (genuine Apple hardware),
 * which stops anyone from filling the team's device slots with invented UDIDs.
 */
class EnrollmentPayloadParser
{
    /**
     * @return array<string, string>
     */
    public function parse(string $body): array
    {
        $caFile = config('storefront.enrollment.device_ca_file');
        $in = tempnam(sys_get_temp_dir(), 'enroll-in');
        $out = tempnam(sys_get_temp_dir(), 'enroll-out');

        try {
            file_put_contents($in, $body);
            $verified = openssl_cms_verify(
                $in,
                OPENSSL_CMS_BINARY | ($caFile ? 0 : OPENSSL_CMS_NOVERIFY),
                null,
                $caFile ? [$caFile] : [],
                null,
                $out,
                null,
                null,
                OPENSSL_ENCODING_DER,
            );

            if (! $verified) {
                throw new ApiException(ErrorCode::EnrollmentPayloadInvalid, details: ['reason' => 'signature']);
            }

            $plist = new CFPropertyList;
            $plist->parse((string) file_get_contents($out));
            $values = $plist->toArray();
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ApiException(ErrorCode::EnrollmentPayloadInvalid, details: ['reason' => 'format']);
        } finally {
            @unlink($in);
            @unlink($out);
            while (openssl_error_string() !== false) {
                // Drain OpenSSL's error queue so later calls start clean.
            }
        }

        if (! is_array($values)) {
            throw new ApiException(ErrorCode::EnrollmentPayloadInvalid, details: ['reason' => 'format']);
        }

        return array_map(fn ($value) => is_scalar($value) ? (string) $value : '', $values);
    }
}
