<?php

use App\Services\Signing\Delta\ComposedPlan;
use App\Services\Signing\Delta\DeltaComposer;
use App\Services\Signing\Delta\DeltaException;
use App\Services\Signing\Delta\ZipDirectory;

/**
 * Writes a ZIP by hand, so a test can choose the details a real signer produces: data descriptors
 * (flag bit 3), stored or deflated entries, padding between entries.
 *
 * @param  array<string, string|array{data: string, descriptor?: bool, method?: int, padding?: string}>  $entries
 */
function deltaZip(string $path, array $entries): void
{
    $out = '';
    $directory = '';
    foreach ($entries as $name => $spec) {
        $spec = is_array($spec) ? $spec : ['data' => $spec];
        $data = $spec['data'];
        $method = $spec['method'] ?? 8;
        $stored = $method === 8 ? gzdeflate($data) : $data;
        $descriptor = $spec['descriptor'] ?? false;
        $crc = crc32($data);
        $offset = strlen($out);
        $flags = $descriptor ? 0x08 : 0;
        $out .= pack('VvvvvvVVVvv', 0x04034B50, 20, $flags, $method, 0, 0x21, $descriptor ? 0 : $crc, $descriptor ? 0 : strlen($stored), $descriptor ? 0 : strlen($data), strlen($name), 0).$name.$stored;
        if ($descriptor) {
            $out .= pack('VVVV', 0x08074B50, $crc, strlen($stored), strlen($data));
        }
        $out .= $spec['padding'] ?? '';
        $directory .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 0x031E, 20, $flags, $method, 0, 0x21, $crc, strlen($stored), strlen($data), strlen($name), 0, 0, 0, 0, str_ends_with($name, '/') ? 0 : 0x81A40000, $offset).$name;
    }
    $count = count($entries);
    file_put_contents($path, $out.$directory.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($directory), strlen($out), 0));
}

/** @param  list<array{string, int}>  $entries */
function deltaPack(string $path, array $files, array $entries, string $baseSha = 'ab', array $extra = []): void
{
    $manifest = json_encode(['version' => 1, 'base_sha256' => $baseSha] + $extra + ['entries' => $entries]);
    deltaZip($path, $files + [DeltaComposer::MANIFEST => ['data' => $manifest, 'method' => 0]]);
}

function composedBytes(ComposedPlan $plan, string $base, string $pack, int $start = 0, ?int $end = null): string
{
    $bytes = '';
    $plan->stream($base, $pack, $start, $end ?? $plan->size - 1, function (string $chunk) use (&$bytes) {
        $bytes .= $chunk;
    }, fn () => true);

    return $bytes;
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/delta-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    $this->base = $this->dir.'/base.ipa';
    $this->pack = $this->dir.'/pack.zip';
    $this->big = str_repeat("resource bytes that compress well \x00\x01\x02", 4000);
    deltaZip($this->base, [
        'Payload/' => '',
        'Payload/App.app/' => '',
        'Payload/App.app/Info.plist' => 'old plist',
        'Payload/App.app/App' => 'old binary',
        'Payload/App.app/data/a.rpf' => ['data' => $this->big, 'descriptor' => true],
        'Payload/App.app/data/b.rpf' => ['data' => $this->big.'b', 'method' => 0, 'padding' => 'PAD'],
        'Payload/App.app/z.txt' => 'last',
    ]);
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*'));
    rmdir($this->dir);
});

it('composes an IPA whose every entry is the pack entry or the untouched base entry', function () {
    deltaPack($this->pack, [
        'Payload/App.app/Info.plist' => 'new plist',
        'Payload/App.app/App' => 'new binary',
        'Payload/App.app/embedded.mobileprovision' => 'profile',
    ], [
        ['Payload/', 0], ['Payload/App.app/', 0],
        ['Payload/App.app/Info.plist', 1], ['Payload/App.app/App', 1], ['Payload/App.app/embedded.mobileprovision', 1],
        ['Payload/App.app/data/a.rpf', 0], ['Payload/App.app/data/b.rpf', 0], ['Payload/App.app/z.txt', 0],
    ]);

    $plan = DeltaComposer::plan($this->base, $this->pack);
    $ipa = $this->dir.'/out.ipa';
    file_put_contents($ipa, composedBytes($plan, $this->base, $this->pack));

    expect(filesize($ipa))->toBe($plan->size)->and($plan->entries)->toBe(8)->and($plan->packEntries)->toBe(3);
    $zip = new ZipArchive;
    expect($zip->open($ipa, ZipArchive::CHECKCONS))->toBeTrue()
        ->and($zip->numFiles)->toBe(8)
        ->and($zip->getFromName('Payload/App.app/Info.plist'))->toBe('new plist')
        ->and($zip->getFromName('Payload/App.app/App'))->toBe('new binary')
        ->and($zip->getFromName('Payload/App.app/embedded.mobileprovision'))->toBe('profile')
        ->and($zip->getFromName('Payload/App.app/data/a.rpf'))->toBe($this->big)
        ->and($zip->getFromName('Payload/App.app/data/b.rpf'))->toBe($this->big.'b')
        ->and($zip->getFromName('Payload/App.app/z.txt'))->toBe('last');
    $zip->close();

    exec('unzip -tq '.escapeshellarg($ipa).' 2>&1', $lines, $status);
    expect($status)->toBe(0, implode("\n", $lines));
});

it('follows the manifest order and drops base entries the signed IPA no longer has', function () {
    deltaPack($this->pack, ['Payload/App.app/Info.plist' => 'new plist'], [
        ['Payload/App.app/z.txt', 0], ['Payload/App.app/Info.plist', 1], ['Payload/App.app/data/a.rpf', 0],
    ]);
    $plan = DeltaComposer::plan($this->base, $this->pack);
    file_put_contents($this->dir.'/out.ipa', composedBytes($plan, $this->base, $this->pack));

    $zip = new ZipArchive;
    $zip->open($this->dir.'/out.ipa', ZipArchive::CHECKCONS);
    $names = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    expect($names)->toBe(['Payload/App.app/z.txt', 'Payload/App.app/Info.plist', 'Payload/App.app/data/a.rpf'])
        ->and($zip->getFromName('Payload/App.app/data/a.rpf'))->toBe($this->big);
});

it('serves any byte range of the composed IPA exactly as the whole file has it', function () {
    deltaPack($this->pack, ['Payload/App.app/App' => 'new binary'], [
        ['Payload/App.app/Info.plist', 0], ['Payload/App.app/App', 1], ['Payload/App.app/data/a.rpf', 0], ['Payload/App.app/data/b.rpf', 0],
    ]);
    $plan = DeltaComposer::plan($this->base, $this->pack);
    $whole = composedBytes($plan, $this->base, $this->pack);
    expect(strlen($whole))->toBe($plan->size);

    mt_srand(7);
    foreach (range(1, 40) as $_) {
        $start = mt_rand(0, $plan->size - 1);
        $end = mt_rand($start, $plan->size - 1);
        expect(composedBytes($plan, $this->base, $this->pack, $start, $end))->toBe(substr($whole, $start, $end - $start + 1));
    }
    expect(composedBytes($plan, $this->base, $this->pack, $plan->size - 1, $plan->size - 1))->toBe(substr($whole, -1));
});

it('turns a run of neighbouring untouched entries into one long read', function () {
    deltaPack($this->pack, ['Payload/App.app/App' => 'new binary'], [
        ['Payload/', 0], ['Payload/App.app/', 0], ['Payload/App.app/Info.plist', 0],
        ['Payload/App.app/App', 1],
        ['Payload/App.app/data/a.rpf', 0], ['Payload/App.app/data/b.rpf', 0], ['Payload/App.app/z.txt', 0],
    ]);
    $plan = DeltaComposer::plan($this->base, $this->pack);

    // base run, pack entry, base run, central directory
    expect(array_column($plan->segments, 0))->toBe([ComposedPlan::BASE, ComposedPlan::PACK, ComposedPlan::BASE, ComposedPlan::MEMORY]);
});

it('stops streaming when the client has gone', function () {
    deltaPack($this->pack, [], [['Payload/App.app/data/a.rpf', 0]]);
    $plan = DeltaComposer::plan($this->base, $this->pack);
    $sent = 0;
    $plan->stream($this->base, $this->pack, 0, $plan->size - 1, function (string $chunk) use (&$sent) {
        $sent += strlen($chunk);
    }, fn () => false);

    expect($sent)->toBe(0);
});

it('checks that local headers sit where the directory says', function () {
    $records = array_values(ZipDirectory::read($this->base)['records']);
    ZipDirectory::checkLocalHeaders($this->base, $records);

    $broken = file_get_contents($this->base);
    $broken[$records[3]->localOffset] = 'X';
    file_put_contents($this->dir.'/broken.ipa', $broken);

    expect(fn () => ZipDirectory::checkLocalHeaders($this->dir.'/broken.ipa', array_values(ZipDirectory::read($this->dir.'/broken.ipa')['records'])))
        ->toThrow(DeltaException::class);
});

it('refuses packs it cannot compose safely', function (array $files, array $entries) {
    deltaPack($this->pack, $files, $entries);

    expect(fn () => DeltaComposer::plan($this->base, $this->pack))->toThrow(DeltaException::class);
})->with([
    'a base name the base lacks' => [[], [['Payload/App.app/missing', 0]]],
    'a pack name the pack lacks' => [[], [['Payload/App.app/Info.plist', 1]]],
    'the same name twice' => [[], [['Payload/App.app/z.txt', 0], ['Payload/App.app/z.txt', 0]]],
    'the manifest listed as an entry' => [[], [[DeltaComposer::MANIFEST, 1]]],
    'a source other than 0 or 1' => [[], [['Payload/App.app/z.txt', 2]]],
    'no entries' => [[], []],
]);

it('refuses a pack without a manifest and a base that is not a zip', function () {
    deltaZip($this->pack, ['Payload/App.app/Info.plist' => 'x']);
    expect(fn () => DeltaComposer::plan($this->base, $this->pack))->toThrow(DeltaException::class);

    deltaPack($this->pack, [], [['Payload/App.app/z.txt', 0]]);
    file_put_contents($this->dir.'/junk.ipa', str_repeat('x', 500));
    expect(fn () => DeltaComposer::plan($this->dir.'/junk.ipa', $this->pack))->toThrow(DeltaException::class);
});

it('refuses zip64 archives', function () {
    $bytes = file_get_contents($this->base);
    // Flag the end record as zip64: entry count 0xFFFF.
    $end = strrpos($bytes, "PK\x05\x06");
    $bytes = substr_replace($bytes, "\xFF\xFF\xFF\xFF", $end + 8, 4);
    file_put_contents($this->dir.'/zip64.ipa', $bytes);

    expect(fn () => ZipDirectory::read($this->dir.'/zip64.ipa'))->toThrow(DeltaException::class);
});

it('writes the manifest\'s record pair into the entries it takes from the base, and only those', function () {
    deltaPack($this->pack, ['Payload/App.app/Info.plist' => 'new plist'], [
        ['Payload/App.app/Info.plist', 1], ['Payload/App.app/data/a.rpf', 0], ['Payload/App.app/z.txt', 0],
    ], extra: ['made_by' => 0, 'external' => 0]);
    $plan = DeltaComposer::plan($this->base, $this->pack);
    file_put_contents($this->dir.'/out.ipa', composedBytes($plan, $this->base, $this->pack));

    $records = ZipDirectory::read($this->dir.'/out.ipa')['records'];
    $pack = ZipDirectory::read($this->pack)['records'];
    $base = ZipDirectory::read($this->base)['records'];
    $pair = fn (string $raw) => unpack('vmade/Vexternal', substr($raw, 4, 2).substr($raw, 38, 4));
    foreach (['Payload/App.app/data/a.rpf', 'Payload/App.app/z.txt'] as $name) {
        expect($pair($records[$name]->raw))->toBe(['made' => 0, 'external' => 0])
            ->and($pair($base[$name]->raw)['external'])->toBe(0x81A40000)
            // The rest of the record is the base's own.
            ->and(substr($records[$name]->raw, 8, 30))->toBe(substr($base[$name]->raw, 8, 30));
    }
    expect($pair($records['Payload/App.app/Info.plist']->raw))->toBe($pair($pack['Payload/App.app/Info.plist']->raw));

    $zip = new ZipArchive;
    expect($zip->open($this->dir.'/out.ipa', ZipArchive::CHECKCONS))->toBeTrue()
        ->and($zip->getFromName('Payload/App.app/data/a.rpf'))->toBe($this->big);
    exec('unzip -tq '.escapeshellarg($this->dir.'/out.ipa').' 2>&1', $lines, $status);
    expect($status)->toBe(0, implode("\n", $lines));
});

it('keeps the base records as they are when the manifest names no pair', function () {
    deltaPack($this->pack, [], [['Payload/App.app/z.txt', 0]]);
    $plan = DeltaComposer::plan($this->base, $this->pack);
    file_put_contents($this->dir.'/out.ipa', composedBytes($plan, $this->base, $this->pack));

    expect(ZipDirectory::read($this->dir.'/out.ipa')['records']['Payload/App.app/z.txt']->externalAttributes)->toBe(0x81A40000);
});

it('refuses a record pair that is not two unsigned integers', function (array $pair) {
    deltaPack($this->pack, [], [['Payload/App.app/z.txt', 0]], extra: $pair);

    expect(fn () => DeltaComposer::plan($this->base, $this->pack))->toThrow(DeltaException::class);
})->with([
    'made_by too big' => [['made_by' => 70000, 'external' => 0]],
    'external negative' => [['made_by' => 0, 'external' => -1]],
    'a string' => [['made_by' => '0', 'external' => 0]],
    'only one half' => [['made_by' => 0]],
]);
