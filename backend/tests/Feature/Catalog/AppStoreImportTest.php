<?php

use App\Enums\AppVisibility;
use App\Enums\RoleSlug;
use App\Models\AppCategory;
use App\Models\CatalogApp;
use App\Models\TeamAppEligibility;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\IpaBuilder;

function appStoreImage(int $width, int $height, string $format): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
    ob_start();
    $format === 'png' ? imagepng($image) : imagejpeg($image);

    return (string) ob_get_clean();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function appStoreListing(array $overrides = []): array
{
    return $overrides + [
        'kind' => 'software',
        'trackId' => 1600529900,
        'trackName' => 'AmneziaVPN',
        'description' => 'Бесплатное приложение с открытым исходным кодом для личного VPN.',
        'sellerName' => 'Privacy Technologies OU',
        'sellerUrl' => 'https://amnezia.org/',
        'primaryGenreId' => 6002,
        'primaryGenreName' => 'Утилиты',
        'genreIds' => ['6002', '6007'],
        'contentAdvisoryRating' => '17+',
        'artworkUrl512' => 'https://is1-ssl.mzstatic.com/image/thumb/Purple/v4/icon/AppIcon/512x512bb.jpg',
        'screenshotUrls' => [
            'https://is1-ssl.mzstatic.com/image/thumb/Purple/v4/a/392x696bb.jpg',
            'https://is1-ssl.mzstatic.com/image/thumb/Purple/v4/b/392x696bb.jpg',
        ],
    ];
}

beforeEach(function () {
    Storage::fake('public');
    $this->team = connectFakeAppleTeam();
    $this->manager = userWithRoles(RoleSlug::CatalogManager);
    AppCategory::factory()->create(['slug' => 'utilities', 'title' => 'Утилиты']);
    $this->fakeAppStore = function (array $results): void {
        Http::fake([
            'itunes.apple.com/lookup*' => Http::response(['resultCount' => count($results), 'results' => $results]),
            '*.mzstatic.com/*1024x1024bb.png' => Http::response(appStoreImage(1024, 1024, 'png')),
            '*.mzstatic.com/*' => Http::response(appStoreImage(1290, 2796, 'jpeg')),
        ]);
    };
});

it('creates a draft listing with its metadata, images and signing bundle ID from an App Store link', function () {
    ($this->fakeAppStore)([appStoreListing()]);

    $response = asStaff($this->manager)->postJson('/api/v1/admin/apps/import', ['url' => 'https://apps.apple.com/us/app/amneziavpn/id1600529900'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'AmneziaVPN')
        ->assertJsonPath('data.app_store_id', '1600529900')
        ->assertJsonPath('data.bundle_identifier', 'com.ruappstore.amneziavpn')
        ->assertJsonPath('data.visibility', 'DRAFT')
        ->assertJsonPath('data.category.slug', 'utilities')
        ->assertJsonPath('data.publisher.name', 'Privacy Technologies OU')
        ->assertJsonPath('data.age_rating', '17+')
        ->assertJsonPath('data.support_url', 'https://amnezia.org/')
        ->assertJsonPath('data.warnings', []);

    expect($response->json('data.icon_url'))->not->toBeNull()
        ->and($response->json('data.screenshots'))->toHaveCount(2)
        ->and(CatalogApp::sole()->description)->toContain('открытым исходным кодом')
        // Our own bundle ID, so the primary team may sign it without another step.
        ->and(TeamAppEligibility::allows($this->team->id, 'com.ruappstore.amneziavpn'))->toBeTrue();

    // Russian text from the link's storefront; images only from Apple's CDN, in large renditions.
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://itunes.apple.com/lookup')
        && $request['id'] === '1600529900' && $request['country'] === 'us' && $request['lang'] === 'ru_ru');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/1024x1024bb.png'));
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/1290x0w.jpg'));
});

it('refuses a second import of the same App Store app', function () {
    ($this->fakeAppStore)([appStoreListing()]);
    asStaff($this->manager)->postJson('/api/v1/admin/apps/import', ['url' => 'https://apps.apple.com/ru/app/x/id1600529900'])->assertCreated();

    asStaff($this->manager)->postJson('/api/v1/admin/apps/import', ['url' => '1600529900'])
        ->assertStatus(409)->assertJsonPath('error.code', 'CONFLICT');
    expect(CatalogApp::count())->toBe(1);
});

it('refuses links that are not App Store apps and apps the App Store does not have', function () {
    ($this->fakeAppStore)([]);

    asStaff($this->manager)->postJson('/api/v1/admin/apps/import', ['url' => 'https://example.com/app/id1600529900'])
        ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    asStaff($this->manager)->postJson('/api/v1/admin/apps/import', ['url' => 'https://apps.apple.com/ru/app/x/id999999999'])
        ->assertNotFound();
    expect(CatalogApp::count())->toBe(0);
});

it('lets only catalog managers import', function () {
    asStaff(userWithRoles(RoleSlug::Support))->postJson('/api/v1/admin/apps/import', ['url' => '1600529900'])->assertForbidden();
});

it('publishes a draft listing together with its first build', function () {
    ($this->fakeAppStore)([appStoreListing()]);
    $id = asStaff($this->manager)->postJson('/api/v1/admin/apps/import', ['url' => '1600529900'])->json('data.id');
    $listing = CatalogApp::where('public_id', $id)->sole();

    $artifact = inspected(uploadIpa($this->manager, $listing, IpaBuilder::app('org.amnezia.AmneziaVPN')->build()));
    asStaff($this->manager)->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/review", [
        'decision' => 'approve',
        'checklist' => ['source_verified' => true, 'distribution_rights_confirmed' => true, 'inspection_report_reviewed' => true],
        'acknowledge_scan_result' => true,
    ])->assertOk();
    asStaff($this->manager)->postJson("/api/v1/admin/artifacts/{$artifact->public_id}/publish")->assertOk();

    expect($listing->refresh()->visibility)->toBe(AppVisibility::Published);
});
