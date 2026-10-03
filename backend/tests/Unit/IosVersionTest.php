<?php

use App\Support\IosVersion;

it('reads the iOS version from a version or from the build number enrollment reports', function (?string $value, ?string $version) {
    expect(IosVersion::normalize($value))->toBe($version);
})->with([
    'version' => ['18.6', '18.6'],
    'version with patch' => ['17.4.1', '17.4.1'],
    'iOS 26 build' => ['23G83', '26.6'],
    'iOS 27 beta build' => ['24A5240q', '27.0'],
    'iOS 18 build' => ['22A3354', '18.0'],
    'iOS 17.4 build' => ['21E219', '17.4'],
    'iOS 16.7 build' => ['20H30', '16.7'],
    'neither' => ['iPhone OS', null],
    'missing' => [null, null],
]);
