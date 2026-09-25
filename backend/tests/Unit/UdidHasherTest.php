<?php

use App\Services\Devices\UdidHasher;

$key = base64_encode(str_repeat('k', 32));

it('produces the same hash regardless of case and surrounding whitespace', function () use ($key) {
    $hasher = new UdidHasher($key);

    expect($hasher->hash(' 00008030-001a2b3c4d5e6f70 '))
        ->toBe($hasher->hash('00008030-001A2B3C4D5E6F70'))
        ->toHaveLength(64);
});

it('produces different hashes under different keys', function () use ($key) {
    $other = new UdidHasher(base64_encode(str_repeat('x', 32)));

    expect((new UdidHasher($key))->hash('ABC'))->not->toBe($other->hash('ABC'));
});

it('refuses missing or short keys', function (?string $badKey) {
    new UdidHasher($badKey);
})->with([null, '', 'not base64!', base64_encode('too-short')])->throws(RuntimeException::class);
