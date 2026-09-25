<?php

use App\Enums\DeviceFamily;

it('classifies devices from the PRODUCT attribute', function (?string $product, DeviceFamily $family) {
    expect(DeviceFamily::fromProduct($product))->toBe($family);
})->with([
    ['iPhone15,2', DeviceFamily::Iphone],
    ['iPad13,4', DeviceFamily::Ipad],
    ['iPod9,1', DeviceFamily::Ipod],
    ['Watch6,1', DeviceFamily::Unknown],
    [null, DeviceFamily::Unknown],
]);
