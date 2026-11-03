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

/** One chunk as delta-v2 stores it: a fresh raw DEFLATE stream, full-flushed, or finished for an entry's last segment. */
function deltaChunk(string $data, bool $final): string
{
    return deflate_add(deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]), $data, $final ? ZLIB_FINISH : ZLIB_FULL_FLUSH);
}

/**
 * Splits files into team and core segments and returns a delta-v2 manifest, team blob and core, as the
 * runner makes them. $segmented maps a name to its target bytes and the [kind, start, end) segments.
 *
 * @param  array<string, array{data: string, segments: list<array{0: string, 1: int, 2: int}>}>  $segmented
 * @param  list<array{0: string, 1: int}|string>  $order  names (segmented) or [name, kind] (whole or base)
 * @return array{manifest: array<string, mixed>, team: string, core: string}
 */
function deltaV2(array $segmented, array $order): array
{
    // Unrelated chunk first: the core blob's chunks may lie in any order.
    $core = deltaChunk('another entry', false);
    $team = '';
    $entries = [];
    foreach ($order as $item) {
        if (! is_string($item)) {
            $entries[] = $item;

            continue;
        }
        $spec = $segmented[$item];
        $segments = [];
        foreach ($spec['segments'] as $index => [$kind, $start, $end]) {
            $chunk = deltaChunk(substr($spec['data'], $start, $end - $start), $index === count($spec['segments']) - 1);
            if ($kind === 't') {
                $segments[] = ['t', strlen($team), strlen($chunk)];
                $team .= $chunk;
            } else {
                $segments[] = ['c', strlen($core), strlen($chunk)];
                $core .= $chunk;
            }
        }
        $entries[] = [$item, 2, ['crc' => crc32($spec['data']), 'size' => strlen($spec['data']), 'segments' => $segments]];
    }

    return [
        'manifest' => ['version' => 2, 'base_sha256' => 'ab', 'core_sha256' => hash('sha256', $core), 'made_by' => 0, 'external' => 0, 'entries' => $entries],
        'team' => $team,
        'core' => $core,
    ];
}

/** @param  array<string, mixed>  $manifest */
function deltaPackV2(string $path, array $files, string $team, array $manifest, int $teamMethod = 0): void
{
    deltaZip($path, $files + [
        DeltaComposer::TEAM_BLOB => ['data' => $team, 'method' => $teamMethod],
        DeltaComposer::MANIFEST => ['data' => json_encode($manifest), 'method' => 0],
    ]);
}

function composedV2(ComposedPlan $plan, string $base, string $pack, ?string $core, int $start = 0, ?int $end = null): string
{
    $bytes = '';
    $plan->stream($base, $pack, $start, $end ?? $plan->size - 1, function (string $chunk) use (&$bytes) {
        $bytes .= $chunk;
    }, fn () => true, $core);

    return $bytes;
}

describe('delta-v2', function () {
    beforeEach(function () {
        mt_srand(11);
        $noise = fn (int $n) => implode('', array_map(fn () => chr(mt_rand(0, 255)), range(1, $n)));
        // A "Mach-O": first page and signature vary per team, the code between is shared.
        $this->binary = $noise(4096).str_repeat("code page \x90\x90\x1f\xd5", 30000).$noise(2000).str_repeat('more code', 5000).$noise(3000);
        $len = strlen($this->binary);
        $this->dylib = str_repeat('dylib text ', 20000).$noise(5000);
        $this->segmented = [
            'Payload/App.app/App' => ['data' => $this->binary, 'segments' => [['t', 0, 4096], ['c', 4096, $len - 3000], ['t', $len - 3000, $len]]],
            // Non-ASCII name, ends with a core segment (the finished chunk lives in the core).
            'Payload/App.app/Frameworks/Кит.dylib' => ['data' => $this->dylib, 'segments' => [['t', 0, 4096], ['c', 4096, 150000], ['t', 150000, 160000], ['c', 160000, strlen($this->dylib)]]],
        ];
        $this->order = [
            ['Payload/', 0], ['Payload/App.app/', 0],
            ['Payload/App.app/Info.plist', 1],
            'Payload/App.app/App',
            ['Payload/App.app/data/a.rpf', 0], ['Payload/App.app/data/b.rpf', 0],
            'Payload/App.app/Frameworks/Кит.dylib',
            ['Payload/App.app/embedded.mobileprovision', 1], ['Payload/App.app/z.txt', 0],
        ];
        $this->whole = ['Payload/App.app/Info.plist' => 'new plist', 'Payload/App.app/embedded.mobileprovision' => 'profile'];
        $this->v2 = deltaV2($this->segmented, $this->order);
        $this->core = $this->dir.'/core.bin';
        file_put_contents($this->core, $this->v2['core']);
    });

    it('composes segmented entries from the team blob and the core, byte for byte', function () {
        deltaPackV2($this->pack, $this->whole, $this->v2['team'], $this->v2['manifest']);
        expect(DeltaComposer::requiredCore($this->pack))->toBe(hash('sha256', $this->v2['core']));

        $plan = DeltaComposer::plan($this->base, $this->pack, checkHeaders: true, corePath: $this->core);
        $plan->checkSegmented($this->pack, $this->core);
        $ipa = $this->dir.'/out.ipa';
        file_put_contents($ipa, composedV2($plan, $this->base, $this->pack, $this->core));

        expect(filesize($ipa))->toBe($plan->size)->and($plan->entries)->toBe(9)->and($plan->packEntries)->toBe(4)
            ->and($plan->version)->toBe(2)->and($plan->coreSha256)->toBe(hash('sha256', $this->v2['core']))
            ->and(array_column($plan->segments, 0))->toContain(ComposedPlan::CORE);
        $zip = new ZipArchive;
        expect($zip->open($ipa, ZipArchive::CHECKCONS))->toBeTrue()->and($zip->numFiles)->toBe(9);
        $names = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        expect($names)->toBe(array_map(fn ($item) => is_string($item) ? $item : $item[0], $this->order))
            ->and($zip->getFromName('Payload/App.app/App'))->toBe($this->binary)
            ->and($zip->getFromName('Payload/App.app/Frameworks/Кит.dylib'))->toBe($this->dylib)
            ->and($zip->getFromName('Payload/App.app/Info.plist'))->toBe('new plist')
            ->and($zip->getFromName('Payload/App.app/data/a.rpf'))->toBe($this->big)
            ->and($zip->getFromName('Payload/App.app/z.txt'))->toBe('last')
            ->and($zip->statName('Payload/App.app/App')['crc'])->toBe(crc32($this->binary))
            ->and($zip->statName('Payload/App.app/Frameworks/Кит.dylib')['crc'])->toBe(crc32($this->dylib))
            ->and($zip->locateName(DeltaComposer::TEAM_BLOB))->toBeFalse()
            ->and($zip->locateName(DeltaComposer::MANIFEST))->toBeFalse();
        $zip->close();

        $records = ZipDirectory::read($ipa)['records'];
        expect($records['Payload/App.app/Frameworks/Кит.dylib']->flags)->toBe(0x800)
            ->and($records['Payload/App.app/App']->flags)->toBe(0)
            ->and($records['Payload/App.app/App']->method)->toBe(8)
            ->and($records['Payload/App.app/App']->externalAttributes)->toBe(0);
        ZipDirectory::checkLocalHeaders($ipa, array_values($records));

        exec('unzip -tq '.escapeshellarg($ipa).' 2>&1', $lines, $status);
        expect($status)->toBe(0, implode("\n", $lines));
    });

    it('serves any byte range across team, core and generated bytes', function () {
        deltaPackV2($this->pack, $this->whole, $this->v2['team'], $this->v2['manifest']);
        $plan = DeltaComposer::plan($this->base, $this->pack, corePath: $this->core);
        $whole = composedV2($plan, $this->base, $this->pack, $this->core);
        expect(strlen($whole))->toBe($plan->size);

        $edges = [0];
        foreach ($plan->segments as [, , $length]) {
            $edges[] = end($edges) + $length;
        }
        $points = [];
        foreach ($edges as $edge) {
            foreach ([-1, 0, 1] as $delta) {
                if ($edge + $delta >= 0 && $edge + $delta < $plan->size) {
                    $points[] = $edge + $delta;
                }
            }
        }
        foreach ($points as $start) {
            foreach ([$start, min($start + 7, $plan->size - 1), $plan->size - 1] as $end) {
                expect(composedV2($plan, $this->base, $this->pack, $this->core, $start, $end))->toBe(substr($whole, $start, $end - $start + 1));
            }
        }
        mt_srand(3);
        foreach (range(1, 30) as $_) {
            $start = mt_rand(0, $plan->size - 1);
            $end = mt_rand($start, $plan->size - 1);
            expect(composedV2($plan, $this->base, $this->pack, $this->core, $start, $end))->toBe(substr($whole, $start, $end - $start + 1));
        }
    });

    it('leaves the base entries out when asked for the changed entries alone', function () {
        deltaPackV2($this->pack, $this->whole, $this->v2['team'], $this->v2['manifest']);
        $plan = DeltaComposer::plan($this->base, $this->pack, corePath: $this->core, changedOnly: true);
        file_put_contents($this->dir.'/changed.ipa', composedV2($plan, $this->base, $this->pack, $this->core));

        $zip = new ZipArchive;
        expect($zip->open($this->dir.'/changed.ipa', ZipArchive::CHECKCONS))->toBeTrue();
        $names = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        expect($names)->toBe(['Payload/App.app/Info.plist', 'Payload/App.app/App', 'Payload/App.app/Frameworks/Кит.dylib', 'Payload/App.app/embedded.mobileprovision'])
            ->and($zip->getFromName('Payload/App.app/App'))->toBe($this->binary);
    });

    it('needs no core when no segment reads from one', function () {
        $v2 = deltaV2(['Payload/App.app/App' => ['data' => $this->binary, 'segments' => [['t', 0, 4096], ['t', 4096, strlen($this->binary)]]]], [['Payload/App.app/Info.plist', 1], 'Payload/App.app/App']);
        deltaPackV2($this->pack, $this->whole, $v2['team'], $v2['manifest']);

        expect(DeltaComposer::requiredCore($this->pack))->toBeNull();
        $plan = DeltaComposer::plan($this->base, $this->pack);
        $plan->checkSegmented($this->pack, null);
        file_put_contents($this->dir.'/out.ipa', composedV2($plan, $this->base, $this->pack, null));
        $zip = new ZipArchive;
        expect($zip->open($this->dir.'/out.ipa', ZipArchive::CHECKCONS))->toBeTrue()
            ->and($zip->getFromName('Payload/App.app/App'))->toBe($this->binary)
            ->and($plan->coreSha256)->toBeNull();
    });

    it('refuses packs whose segments it cannot serve', function (Closure $break, bool $withCore = true) {
        $manifest = $this->v2['manifest'];
        $files = $this->whole;
        $teamMethod = 0;
        $break($manifest, $files, $teamMethod);
        deltaPackV2($this->pack, $files, $this->v2['team'], $manifest, $teamMethod);

        expect(fn () => DeltaComposer::plan($this->base, $this->pack, corePath: $withCore ? $this->core : null))->toThrow(DeltaException::class);
    })->with([
        'a team segment past the team blob' => [function (array &$m) {
            $m['entries'][3][2]['segments'][2][2] += 1000000;
        }],
        'a core segment past the core' => [function (array &$m) {
            $m['entries'][3][2]['segments'][1][1] += 1000000;
        }],
        'no core file' => [fn () => null, false],
        'core segments but no core named' => [function (array &$m) {
            $m['core_sha256'] = null;
        }],
        'a core sha256 that is not one' => [function (array &$m) {
            $m['core_sha256'] = 'zz';
        }],
        'the team blob deflated' => [function (array &$m, array &$f, int &$method) {
            $method = 8;
        }],
        'the team blob listed as an entry' => [function (array &$m) {
            $m['entries'][] = [DeltaComposer::TEAM_BLOB, 1];
        }],
        'the manifest listed as an entry' => [function (array &$m) {
            $m['entries'][] = [DeltaComposer::MANIFEST, 1];
        }],
        'a segmented name twice' => [function (array &$m) {
            $m['entries'][] = $m['entries'][3];
        }],
        'an empty segment' => [function (array &$m) {
            $m['entries'][3][2]['segments'][0][2] = 0;
        }],
        'no segments' => [function (array &$m) {
            $m['entries'][3][2]['segments'] = [];
        }],
        'an unknown segment source' => [function (array &$m) {
            $m['entries'][3][2]['segments'][0][0] = 'x';
        }],
        'a size of 4 GiB' => [function (array &$m) {
            $m['entries'][3][2]['size'] = 0xFFFFFFFF;
        }],
        'a crc that is not one' => [function (array &$m) {
            $m['entries'][3][2]['crc'] = -1;
        }],
        'a segmented directory' => [function (array &$m) {
            $m['entries'][3][0] = 'Payload/App.app/Dir/';
        }],
        'kind 2 in a version 1 manifest' => [function (array &$m) {
            $m['version'] = 1;
        }],
    ]);

    it('refuses a team segment when the pack has no team blob', function () {
        deltaZip($this->pack, $this->whole + [DeltaComposer::MANIFEST => ['data' => json_encode($this->v2['manifest']), 'method' => 0]]);

        expect(fn () => DeltaComposer::plan($this->base, $this->pack, corePath: $this->core))->toThrow(DeltaException::class);
    });

    it('finds segmented entries that do not inflate to their size and CRC-32', function (Closure $break) {
        $manifest = $this->v2['manifest'];
        $team = $this->v2['team'];
        $core = $this->v2['core'];
        $break($manifest, $team, $core);
        file_put_contents($this->core, $core);
        deltaPackV2($this->pack, $this->whole, $team, $manifest);
        $plan = DeltaComposer::plan($this->base, $this->pack, corePath: $this->core);

        expect(fn () => $plan->checkSegmented($this->pack, $this->core))->toThrow(DeltaException::class);
    })->with([
        'a wrong crc' => [function (array &$m) {
            $m['entries'][3][2]['crc'] ^= 1;
        }],
        'a larger size' => [function (array &$m) {
            $m['entries'][3][2]['size'] += 1;
        }],
        'a smaller size' => [function (array &$m) {
            $m['entries'][3][2]['size'] -= 1;
        }],
        'a changed core byte' => [function (array &$m, string &$t, string &$c) {
            // Inside the App binary's core chunk, past the unrelated first chunk.
            $at = $m['entries'][3][2]['segments'][1][1] + 100;
            $c[$at] = chr(ord($c[$at]) ^ 0x55);
        }],
        'the last segment not final' => [function (array &$m) {
            array_pop($m['entries'][3][2]['segments']);
        }],
        'segments after the final block' => [function (array &$m) {
            $m['entries'][6][2]['segments'][] = $m['entries'][6][2]['segments'][0];
        }],
    ]);
});
