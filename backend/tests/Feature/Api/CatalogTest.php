<?php

use App\Enums\ArtifactStatus;
use App\Models\AppArtifact;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\AppVersion;
use App\Models\CatalogApp;
use App\Models\Device;
use App\Models\Installation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('lists only published listings', function () {
    CatalogApp::factory()->create(['name' => 'Visible']);
    CatalogApp::factory()->draft()->create(['name' => 'Draft']);
    CatalogApp::factory()->hidden()->create(['name' => 'Hidden']);
    CatalogApp::factory()->create(['name' => 'Deleted'])->delete();

    $this->getJson('/api/v1/apps')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Visible')
        ->assertJsonPath('meta.pagination.total', 1);
});

it('returns 404 for listings customers cannot see', function (string $state) {
    $app = CatalogApp::factory()->{$state}()->create();

    $this->getJson("/api/v1/apps/{$app->public_id}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
})->with(['draft', 'hidden']);

it('searches name, subtitle, publisher and category', function () {
    $category = AppCategory::factory()->create(['title' => 'Утилиты']);
    CatalogApp::factory()->create(['name' => 'Paper Scan', 'category_id' => $category->id]);
    CatalogApp::factory()->create(['name' => 'Orbit Mail', 'subtitle' => 'Почта, которая не отвлекает']);
    CatalogApp::factory()->create(['name' => 'Atlas', 'publisher_id' => AppPublisher::factory()->create(['name' => 'Atlas Maps'])->id]);

    $names = fn (string $q) => collect($this->getJson('/api/v1/apps?'.http_build_query(['q' => $q]))->json('data'))->pluck('name')->all();

    expect($names('paper'))->toBe(['Paper Scan'])
        ->and($names('ПОЧТА'))->toBe(['Orbit Mail'])
        ->and($names('atlas maps'))->toBe(['Atlas'])
        ->and($names('утилит'))->toBe(['Paper Scan'])
        ->and($names('%'))->toBe([]);
});

it('filters by category slug and paginates', function () {
    $games = AppCategory::factory()->create(['slug' => 'games']);
    CatalogApp::factory()->count(3)->create(['category_id' => $games->id]);
    CatalogApp::factory()->count(2)->create();

    $this->getJson('/api/v1/apps?category=games&per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.pagination', ['page' => 2, 'per_page' => 2, 'total' => 3, 'last_page' => 2]);
});

it('returns the app page with its latest version', function () {
    $app = CatalogApp::factory()->create(['description' => 'Длинное описание']);
    AppVersion::factory()->for($app, 'app')->create(['version' => '1.0', 'released_at' => now()->subMonth()]);
    AppVersion::factory()->for($app, 'app')->create(['version' => '1.1', 'release_notes' => 'Исправления', 'released_at' => now()]);

    $this->getJson("/api/v1/apps/{$app->public_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $app->public_id)
        ->assertJsonPath('data.description', 'Длинное описание')
        ->assertJsonPath('data.latest_version.version', '1.1')
        ->assertJsonPath('data.release_notes', 'Исправления')
        ->assertJsonPath('data.screenshots', []);
});

it('lists versions newest first', function () {
    $app = CatalogApp::factory()->create();
    AppVersion::factory()->for($app, 'app')->create(['version' => '1.0', 'released_at' => now()->subYear()]);
    AppVersion::factory()->for($app, 'app')->create(['version' => '2.0', 'released_at' => now()]);

    $this->getJson("/api/v1/apps/{$app->public_id}/versions")
        ->assertOk()
        ->assertJsonPath('data.0.version', '2.0')
        ->assertJsonPath('data.1.version', '1.0');
});

it('builds the feed from featured, recently updated and category sections', function () {
    $featured = CatalogApp::factory()->featured(1)->create();
    AppVersion::factory()->for($featured, 'app')->create();
    CatalogApp::factory()->draft()->featured(2)->create();

    $feed = $this->getJson('/api/v1/storefront/feed')->assertOk()->json('data.sections');

    // No installations yet, so the download rankings are left out rather than invented.
    expect(collect($feed)->pluck('id')->all())->toBe(['featured', 'recently_updated', 'new', 'categories'])
        ->and($feed[0]['apps'])->toHaveCount(1)
        ->and($feed[0]['apps'][0]['id'])->toBe($featured->public_id)
        ->and($feed[3]['categories'][0]['app_count'])->toBe(1);
});

it('ranks by real downloads and splits games from apps', function () {
    $games = AppCategory::factory()->create(['kind' => 'GAMES', 'title' => 'Игры']);
    $racer = CatalogApp::factory()->create(['category_id' => $games->id, 'name' => 'Racer']);
    $puzzle = CatalogApp::factory()->create(['category_id' => $games->id, 'name' => 'Puzzle']);
    $notes = CatalogApp::factory()->create(['name' => 'Notes']);

    $deliver = function (CatalogApp $app, int $times, int $daysAgo) {
        $artifact = AppArtifact::factory()->for($app, 'app')->status(ArtifactStatus::Published)->create();
        for ($i = 0; $i < $times; $i++) {
            Installation::create([
                'user_id' => User::factory()->create()->id, 'device_id' => Device::factory()->create()->id,
                'app_id' => $app->id, 'artifact_id' => $artifact->id, 'status' => 'DELIVERED', 'delivered_at' => now()->subDays($daysAgo),
            ]);
        }
    };
    $deliver($racer, 3, 30);   // popular, but not recently
    $deliver($puzzle, 1, 1);   // fewer, but this week
    $deliver($notes, 5, 1);

    $sections = collect($this->getJson('/api/v1/storefront/feed?kind=games')->assertOk()->json('data.sections'))->keyBy('id');

    expect($sections['most_downloaded']['title'])->toBe('Самые скачиваемые игры')
        ->and(collect($sections['most_downloaded']['apps'])->pluck('name')->all())->toBe(['Racer', 'Puzzle'])
        ->and(collect($sections['trending']['apps'])->pluck('name')->all())->toBe(['Puzzle'])
        ->and(collect($sections['categories']['categories'])->pluck('kind')->unique()->all())->toBe(['GAMES']);

    $this->getJson('/api/v1/apps?kind=apps')->assertOk()->assertJsonPath('data.0.name', 'Notes')->assertJsonPath('data.0.category.kind', 'APPS');
    $this->getJson('/api/v1/apps?kind=games&sort=new')->assertOk()->assertJsonPath('data.0.name', 'Puzzle');
});

it('omits empty feed sections', function () {
    $this->getJson('/api/v1/storefront/feed')->assertOk()->assertJsonPath('data.sections', []);
});

describe('install_state', function () {
    it('is unavailable while no artifact is published', function () {
        $app = CatalogApp::factory()->create();
        AppArtifact::factory()->for($app, 'app')->status(ArtifactStatus::Ready)->create();

        $this->getJson("/api/v1/apps/{$app->public_id}")
            ->assertJsonPath('data.install_state', [
                'status' => 'unavailable',
                'reason' => 'ARTIFACT_NOT_INSTALLABLE',
                'progress' => null,
                'installation_id' => null,
            ]);
    });

    it('asks guests to sign in once an artifact is published', function () {
        $app = CatalogApp::factory()->create();
        AppArtifact::factory()->for($app, 'app')->status(ArtifactStatus::Published)->create(['size_bytes' => 1234]);
        AppVersion::factory()->for($app, 'app')->create();

        $this->getJson("/api/v1/apps/{$app->public_id}")
            ->assertJsonPath('data.install_state.status', 'not_eligible')
            ->assertJsonPath('data.install_state.reason', 'UNAUTHENTICATED')
            ->assertJsonPath('data.latest_version.size_bytes', 1234);
    });

    it('never reports a signed-in user as installable before device registration exists', function () {
        Sanctum::actingAs(User::factory()->create());
        $app = CatalogApp::factory()->create();
        AppArtifact::factory()->for($app, 'app')->status(ArtifactStatus::Published)->create();

        $this->getJson('/api/v1/apps')
            ->assertJsonPath('data.0.install_state.status', 'not_eligible')
            ->assertJsonPath('data.0.install_state.reason', 'DEVICE_NOT_ELIGIBLE');
    });
});

it('reports the signed-out storefront status', function () {
    $this->getJson('/api/v1/storefront/status')
        ->assertOk()
        ->assertJsonPath('data.stage', 'signed_out')
        ->assertJsonPath('data.next_action', 'sign_in');
});
