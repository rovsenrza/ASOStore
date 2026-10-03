<?php

use App\Services\Imports\LinkFetcher;
use App\Services\Imports\LinkFetchFailed;
use Tests\Support\FakeLinkFetcher;

it('accepts only public addresses', function (string $address, bool $public) {
    expect(LinkFetcher::isPublic($address))->toBe($public);
})->with([
    ['93.184.216.34', true],
    ['2606:2800:220:1:248:1893:25c8:1946', true],
    ['127.0.0.1', false],
    ['10.1.2.3', false],
    ['172.20.0.1', false],
    ['192.168.1.1', false],
    ['169.254.169.254', false],
    ['100.64.0.1', false],
    ['0.0.0.0', false],
    ['::1', false],
    ['::ffff:127.0.0.1', false],
    ['fd00::1', false],
    ['fe80::1', false],
]);

it('refuses links that are not plain https on port 443', function (string $url) {
    expect(fn () => (new FakeLinkFetcher([]))->assertAcceptable($url))->toThrow(LinkFetchFailed::class);
})->with([
    'http://example.com/a.ipa',
    'ftp://example.com/a.ipa',
    'file:///etc/passwd',
    'https://user:pass@example.com/a.ipa',
    'https://example.com:8443/a.ipa',
    'https://localhost/a.ipa',
    'https://metadata.google.internal/a.ipa',
    'not a link',
]);

it('refuses a host that resolves to a private address, even alongside a public one', function () {
    $fetcher = new FakeLinkFetcher([], ['evil.example' => ['93.184.216.34', '10.0.0.5']]);

    expect(fn () => $fetcher->vettedAddress('evil.example'))->toThrow(LinkFetchFailed::class, 'недоступна');
});

it('re-checks every redirect hop', function () {
    $fetcher = new FakeLinkFetcher(
        [['location' => 'https://internal.example/a.ipa']],
        ['internal.example' => ['192.168.0.10']],
    );
    $path = tempnam(sys_get_temp_dir(), 't');

    expect(fn () => $fetcher->fetch('https://files.example/a.ipa', $path, 1024))->toThrow(LinkFetchFailed::class, 'недоступна');
    expect($fetcher->requested)->toBe(['https://files.example/a.ipa']);
});

it('follows a relative redirect and names the file from Content-Disposition', function () {
    $fetcher = new FakeLinkFetcher([
        ['location' => '/dl/real'],
        ['body' => "PK\x03\x04rest", 'disposition' => 'attachment; filename="Cool App.ipa"'],
    ]);
    $path = tempnam(sys_get_temp_dir(), 't');

    $file = $fetcher->fetch('https://files.example/share/x', $path, 1024);

    expect($fetcher->requested)->toBe(['https://files.example/share/x', 'https://files.example/dl/real'])
        ->and($file['filename'])->toBe('Cool App.ipa')
        ->and($file['size_bytes'])->toBe(8);
});

it('rejects a web page instead of an IPA', function () {
    $fetcher = new FakeLinkFetcher([['body' => '<!doctype html><html>']]);

    expect(fn () => $fetcher->fetch('https://files.example/a.ipa', tempnam(sys_get_temp_dir(), 't'), 1024))
        ->toThrow(LinkFetchFailed::class, 'не файл IPA');
});

it('stops after too many redirects', function () {
    $fetcher = new FakeLinkFetcher(array_fill(0, 10, ['location' => 'https://files.example/again']));

    expect(fn () => $fetcher->fetch('https://files.example/a.ipa', tempnam(sys_get_temp_dir(), 't'), 1024))
        ->toThrow(LinkFetchFailed::class, 'перенаправлений');
});

it('turns cloud share links into direct downloads', function () {
    $fetcher = new FakeLinkFetcher([]);

    expect($fetcher->normalize('https://www.dropbox.com/s/abc/App.ipa?dl=0'))->toBe('https://www.dropbox.com/s/abc/App.ipa?dl=1')
        ->and($fetcher->normalize('https://drive.google.com/file/d/1AbC_d-9/view?usp=sharing'))
        ->toBe('https://drive.usercontent.google.com/download?id=1AbC_d-9&export=download&confirm=t');
});
