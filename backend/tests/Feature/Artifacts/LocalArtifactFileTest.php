<?php

use App\Services\Artifacts\LocalArtifactFile;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Facades\Storage;

it('uses a local file in place and never deletes it', function () {
    Storage::fake('artifacts')->put('originals/ab/file.ipa', 'ipa-bytes');

    $file = LocalArtifactFile::open(Storage::disk('artifacts'), 'originals/ab/file.ipa');
    $file->release();

    expect($file->path)->toBe(Storage::disk('artifacts')->path('originals/ab/file.ipa'))
        ->and(file_get_contents($file->path))->toBe('ipa-bytes');
});

it('copies an object storage file to a private temporary file and removes it on release', function () {
    $mock = new MockHandler([fn (CommandInterface $command) => new Result(['Body' => Utils::streamFor('remote-ipa-bytes')])]);
    $disk = Storage::build([
        'driver' => 's3', 'key' => 'key', 'secret' => 'secret', 'region' => 'default', 'bucket' => 'ruappstore-artifacts',
        'endpoint' => 'https://storage.test', 'use_path_style_endpoint' => true, 'throw' => true, 'handler' => $mock,
    ]);

    $file = LocalArtifactFile::open($disk, 'signed/ab/build.ipa');
    expect(file_get_contents($file->path))->toBe('remote-ipa-bytes')
        ->and(fileperms($file->path) & 0777)->toBe(0600);

    $file->release();
    expect(is_file($file->path))->toBeFalse();
});
