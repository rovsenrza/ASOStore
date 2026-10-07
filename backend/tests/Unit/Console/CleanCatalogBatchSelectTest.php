<?php

use App\Console\Commands\CleanCatalogBatch;

function modules(string ...$names): array
{
    return ['modules' => array_map(fn (string $name) => ['path' => "Payload/G.app/Frameworks/{$name}", 'removable' => true], $names)];
}

$rules = ['remove' => ['*'], 'keep' => ['masterSideloadFix*', 'dark.dylib', 'Fixipa*'], 'runtime' => ['libsubstrate*']];

it('removes mod menus and cheat libraries but keeps sideload fixes', function () use ($rules) {
    $selection = CleanCatalogBatch::select(modules('iGameGod', 'SpeedStars.dylib', 'masterSideloadFix.dylib', 'dark.dylib'), $rules);

    expect(array_map('basename', $selection['remove']))->toBe(['iGameGod', 'SpeedStars.dylib'])
        ->and($selection['fix_metadata'])->toBeTrue();
});

it('removes nothing when only sideload fixes are present', function () use ($rules) {
    expect(CleanCatalogBatch::select(modules('masterSideloadFix.dylib', 'dark.dylib'), $rules)['remove'])->toBe([]);
});

it('takes the bundled hook runtime along only when nothing else stays', function () use ($rules) {
    $all = CleanCatalogBatch::select(modules('Firepurchase.dylib', 'LocalIAPStore14.dylib', 'libsubstrate.dylib'), $rules);
    $some = CleanCatalogBatch::select(modules('Firepurchase.dylib', 'Fixipa1.dylib', 'libsubstrate.dylib'), $rules);

    expect(array_map('basename', $all['remove']))->toContain('libsubstrate.dylib')
        ->and(array_map('basename', $some['remove']))->toBe(['Firepurchase.dylib']);
});

it('never touches a module the analysis cannot remove safely', function () use ($rules) {
    $analysis = ['modules' => [['path' => 'Payload/G.app/Frameworks/iGameGod', 'removable' => false]]];

    expect(CleanCatalogBatch::select($analysis, $rules)['remove'])->toBe([]);
});
