<?php

use Tests\Support\IpaBuilder;

/*
 * Writes a synthetic IPA for browser tests:
 *   php tests/Support/make-ipa.php OUT [bundle] [version] [build] [padding MiB]
 * Padding is random (incompressible) data, to make multi-chunk uploads.
 */

require __DIR__.'/../../vendor/autoload.php';

[$out, $bundle, $version, $build, $padding] = [$argv[1], $argv[2] ?? 'com.example.e2e', $argv[3] ?? '1.0.0', $argv[4] ?? '1', (int) ($argv[5] ?? 0)];
$builder = IpaBuilder::app($bundle)->version($version, $build);
if ($padding > 0) {
    $builder->withAppFile('Assets.car', random_bytes($padding * 1024 * 1024));
}
file_put_contents($out, $builder->build());
