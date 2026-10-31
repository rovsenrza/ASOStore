<?php

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Services\Devices\EnrollmentPayloadParser;

/** The reason an invalid payload is refused with, or null when it parses. */
function enrollmentRefusal(string $body): ?string
{
    try {
        app(EnrollmentPayloadParser::class)->parse($body);
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe(ErrorCode::EnrollmentPayloadInvalid);

        return $e->details['reason'] ?? null;
    }

    return null;
}

it('reads the attributes of a signed device answer as strings', function () {
    $values = app(EnrollmentPayloadParser::class)->parse(devicePayload([
        'UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '22A3354', 'CHALLENGE' => 'abc',
    ]));

    expect($values)->toBe(['UDID' => TEST_UDID, 'PRODUCT' => 'iPhone15,2', 'VERSION' => '22A3354', 'CHALLENGE' => 'abc']);
});

it('refuses a body that is not a signed message', function () {
    expect(enrollmentRefusal('not a cms message'))->toBe('signature')
        ->and(enrollmentRefusal(''))->toBe('signature');
});

it('refuses a signed message whose content was altered', function () {
    $der = devicePayload(['UDID' => TEST_UDID, 'CHALLENGE' => 'abc']);
    $tampered = str_replace(TEST_UDID, '00008030-FFFFFFFFFFFFFFFF', $der);
    expect($tampered)->not->toBe($der)
        ->and(enrollmentRefusal($tampered))->toBe('signature');
});

it('refuses a self-signed device when an Apple device CA is required', function () {
    // Any CA other than the payload's signer: the test identity does not chain to it.
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'Other CA'], $key), null, $key, 1), $pem);
    $ca = tempnam(sys_get_temp_dir(), 'device-ca');
    file_put_contents($ca, $pem);
    config(['storefront.enrollment.device_ca_file' => $ca]);

    try {
        expect(enrollmentRefusal(devicePayload(['UDID' => TEST_UDID])))->toBe('signature');
    } finally {
        unlink($ca);
    }
});
