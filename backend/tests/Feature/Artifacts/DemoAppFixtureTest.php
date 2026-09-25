<?php

use App\Services\Inspection\IpaInspector;

/*
 * The real DemoApp (fixtures/DemoApp, built with scripts/export-demo-ipa.sh on a Mac)
 * must pass inspection. Skipped where the fixture has not been built, e.g. on Linux CI.
 */
it('inspects the real DemoApp IPA as a clean arm64 iOS app', function () {
    $ipa = base_path('../fixtures/DemoApp/build/DemoApp.ipa');
    if (! is_file($ipa)) {
        $this->markTestSkipped('Run scripts/export-demo-ipa.sh to build the fixture.');
    }

    $result = app(IpaInspector::class)->inspect($ipa);

    expect($result->passed())->toBeTrue()
        ->and($result->report['binaries'][0])->toMatchArray(['architectures' => ['arm64'], 'encrypted' => false, 'platform' => 'ios', 'min_os' => '18.0'])
        ->and($result->report['compatibility_issues'])->toBe([]);

    $this->artisan('ipa:inspect', ['path' => $ipa])->assertSuccessful();
});

it('reports a missing file', function () {
    $this->artisan('ipa:inspect', ['path' => '/nonexistent.ipa'])->assertFailed();
});
