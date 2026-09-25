<?php

/**
 * Writes to stdout what an iPhone posts back to the enrollment Profile Service
 * URL: a DER-encoded CMS message wrapping a plist with the device attributes,
 * signed by a throwaway "device" certificate. For local tests and examples only.
 *
 *   php scripts/device-payload.php CHALLENGE [UDID] [PRODUCT] [VERSION] > payload.der
 */

require __DIR__.'/../backend/vendor/autoload.php';

[$challenge, $udid, $product, $version] = array_pad(array_slice($argv, 1), 4, null);
if (! $challenge) {
    fwrite(STDERR, "usage: php scripts/device-payload.php CHALLENGE [UDID] [PRODUCT] [VERSION]\n");
    exit(1);
}

$plist = new CFPropertyList\CFPropertyList;
$plist->add((new CFPropertyList\CFTypeDetector)->toCFType([
    'CHALLENGE' => $challenge,
    'UDID' => $udid ?? '00008030-001A2B3C4D5E6F70',
    'PRODUCT' => $product ?? 'iPhone15,2',
    'VERSION' => $version ?? '22A3354',
]));

$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
$certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test iPhone'], $key), null, $key, 1);
openssl_x509_export($certificate, $certificatePem);
openssl_pkey_export($key, $keyPem);

$in = tempnam(sys_get_temp_dir(), 'payload-in');
$out = tempnam(sys_get_temp_dir(), 'payload-out');
file_put_contents($in, $plist->toXML());
openssl_cms_sign($in, $out, $certificatePem, $keyPem, [], OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER);
fwrite(STDOUT, (string) file_get_contents($out));
unlink($in);
unlink($out);
