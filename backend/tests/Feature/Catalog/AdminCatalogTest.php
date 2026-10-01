<?php

use App\Enums\RoleSlug;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Models\AuditLog;
use App\Models\CatalogApp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function pngUpload(int $width, int $height, string $name = 'image.png'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));
    $path = tempnam(sys_get_temp_dir(), 'img').'.png';
    imagepng($image, $path);

    return new UploadedFile($path, $name, 'image/png', null, true);
}

beforeEach(function () {
    Storage::fake('public');
    $this->manager = userWithRoles(RoleSlug::CatalogManager);
    $this->category = AppCategory::factory()->create(['slug' => 'tools', 'title' => 'Инструменты']);
    $this->publisher = AppPublisher::factory()->create(['name' => 'North Studio']);
    $this->create = fn (array $overrides = []) => asStaff($this->manager)->postJson('/api/v1/admin/apps', $overrides + [
        'name' => 'Заметки Про',
        'subtitle' => 'Все мысли рядом',
        'description' => 'Описание',
        'category_id' => $this->category->public_id,
        'publisher_id' => $this->publisher->public_id,
        'source_type' => 'OWN_BUILD',
    ]);
});

it('creates listings as drafts that customers cannot see', function () {
    $app = ($this->create)()->assertCreated()
        ->assertJsonPath('data.visibility', 'DRAFT')
        ->assertJsonPath('data.slug', 'zametki-pro')
        ->assertJsonPath('data.source_type', 'OWN_BUILD')
        ->json('data');
    forgetGuards();

    $this->getJson("/api/v1/apps/{$app['id']}")->assertNotFound();
    expect(AuditLog::where('action', 'app.created')->sole()->subject_id)->toBe($app['id']);
});

it('requires a source type for every listing', function () {
    ($this->create)(['source_type' => null])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['source_type']]]]);
    ($this->create)(['source_type' => 'DOWNLOADED_SOMEWHERE'])->assertUnprocessable();
});

it('publishes a listing and records the visibility change', function () {
    $id = ($this->create)()->json('data.id');

    $this->patchJson("/api/v1/admin/apps/{$id}", ['visibility' => 'PUBLISHED', 'reason' => 'Готово к показу'])
        ->assertOk()
        ->assertJsonPath('data.visibility', 'PUBLISHED');
    forgetGuards();

    $this->getJson("/api/v1/apps/{$id}")->assertOk()->assertJsonPath('data.install_state.status', 'unavailable');
    $event = AuditLog::where('action', 'app.visibility_changed')->sole();
    expect($event->before)->toBe(['visibility' => 'DRAFT'])
        ->and($event->after)->toBe(['visibility' => 'PUBLISHED'])
        ->and($event->reason)->toBe('Готово к показу');
});

it('soft-deletes and restores listings', function () {
    $id = ($this->create)(['visibility' => 'PUBLISHED'])->json('data.id');

    $this->deleteJson("/api/v1/admin/apps/{$id}", ['reason' => 'Дубликат'])->assertOk();
    expect(CatalogApp::withTrashed()->where('public_id', $id)->sole()->trashed())->toBeTrue();
    $this->getJson('/api/v1/admin/apps?deleted=1')->assertJsonPath('data.0.id', $id);
    forgetGuards();
    $this->getJson("/api/v1/apps/{$id}")->assertNotFound();

    asStaff($this->manager)->postJson("/api/v1/admin/apps/{$id}/restore")->assertOk()->assertJsonPath('data.deleted_at', null);
});

it('normalises the icon to a square PNG and rejects non-square images', function () {
    $id = ($this->create)()->json('data.id');

    $this->post("/api/v1/admin/apps/{$id}/icon", ['icon' => pngUpload(800, 600)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.icon.0', 'Иконка должна быть квадратной, не меньше 512×512 пикселей.');

    $url = $this->post("/api/v1/admin/apps/{$id}/icon", ['icon' => pngUpload(1024, 1024)], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('data.icon_url');

    $path = CatalogApp::where('public_id', $id)->value('icon_path');
    Storage::disk('public')->assertExists($path);
    expect(getimagesizefromstring(Storage::disk('public')->get($path)))->toMatchArray([0 => 384, 1 => 384, 'mime' => 'image/webp'])
        ->and($url)->toContain($path);
});

it('resizes screenshots, orders them and shows them to customers', function () {
    $id = ($this->create)(['visibility' => 'PUBLISHED'])->json('data.id');

    $first = $this->post("/api/v1/admin/apps/{$id}/screenshots", ['screenshot' => pngUpload(2000, 4000)], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('data.width', 1290)
        ->assertJsonPath('data.height', 2580)
        ->json('data.id');
    $second = $this->post("/api/v1/admin/apps/{$id}/screenshots", ['screenshot' => pngUpload(1170, 2532)], ['Accept' => 'application/json'])->json('data.id');

    $this->putJson("/api/v1/admin/apps/{$id}/screenshots/order", ['order' => [$second, $first]])
        ->assertOk()
        ->assertJsonPath('data.0.id', $second);
    forgetGuards();

    $this->getJson("/api/v1/apps/{$id}")
        ->assertJsonCount(2, 'data.screenshots')
        ->assertJsonPath('data.screenshots.0.width', 1170);

    asStaff($this->manager)->deleteJson("/api/v1/admin/apps/{$id}/screenshots/{$second}")->assertOk();
    forgetGuards();
    $this->getJson("/api/v1/apps/{$id}")->assertJsonCount(1, 'data.screenshots');
});

it('shows the banner set in the admin on hero cards, never a screenshot', function () {
    $id = ($this->create)(['visibility' => 'PUBLISHED'])->json('data.id');
    $this->post("/api/v1/admin/apps/{$id}/screenshots", ['screenshot' => pngUpload(1170, 2532)], ['Accept' => 'application/json'])->assertCreated();
    forgetGuards();
    // A portrait screenshot does not fit a wide card.
    $this->getJson("/api/v1/apps/{$id}")->assertJsonPath('data.feature_image_url', null);

    asStaff($this->manager)->post("/api/v1/admin/apps/{$id}/banner", ['banner' => pngUpload(1170, 2532)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['banner']]]]);
    $url = $this->post("/api/v1/admin/apps/{$id}/banner", ['banner' => pngUpload(2400, 1500)], ['Accept' => 'application/json'])
        ->assertOk()
        ->json('data.banner_url');

    $path = CatalogApp::where('public_id', $id)->value('banner_path');
    expect(getimagesizefromstring(Storage::disk('public')->get($path)))->toMatchArray([0 => 1280, 1 => 800, 'mime' => 'image/webp']);
    $this->getJson('/api/v1/admin/apps?visibility=PUBLISHED')->assertJsonPath('data.0.banner_url', $url);
    forgetGuards();
    $this->getJson("/api/v1/apps/{$id}")->assertJsonPath('data.feature_image_url', $url);

    asStaff($this->manager)->deleteJson("/api/v1/admin/apps/{$id}/banner")->assertOk();
    Storage::disk('public')->assertMissing($path);
    forgetGuards();
    $this->getJson("/api/v1/apps/{$id}")->assertJsonPath('data.feature_image_url', null);
});

it('adds versions with release notes and refuses duplicates', function () {
    $id = ($this->create)(['visibility' => 'PUBLISHED'])->json('data.id');

    $version = $this->postJson("/api/v1/admin/apps/{$id}/versions", ['version' => '1.2.0', 'build_number' => '42', 'release_notes' => 'Новое'])
        ->assertCreated()
        ->json('data');
    $this->postJson("/api/v1/admin/apps/{$id}/versions", ['version' => '1.2.0', 'build_number' => '42'])
        ->assertStatus(409)->assertJsonPath('error.code', 'VERSION_EXISTS');
    $this->patchJson("/api/v1/admin/app-versions/{$version['id']}", ['release_notes' => 'Исправления'])->assertOk();
    forgetGuards();

    $this->getJson("/api/v1/apps/{$id}")->assertJsonPath('data.latest_version.version', '1.2.0')->assertJsonPath('data.release_notes', 'Исправления');
});

it('manages categories and publishers', function () {
    asStaff($this->manager)->postJson('/api/v1/admin/categories', ['title' => 'Фото и видео'])
        ->assertCreated()->assertJsonPath('data.slug', 'foto-i-video');
    $this->postJson('/api/v1/admin/publishers', ['name' => 'North Studio'])->assertUnprocessable();
    $publisher = $this->postJson('/api/v1/admin/publishers', ['name' => 'Mono Labs', 'website' => 'https://mono.example'])->assertCreated()->json('data');
    $this->patchJson("/api/v1/admin/publishers/{$publisher['id']}", ['support_email' => 'help@mono.example'])->assertOk();

    $this->getJson('/api/v1/admin/categories')->assertOk()->assertJsonFragment(['title' => 'Инструменты']);
    $this->getJson('/api/v1/admin/publishers')->assertOk()->assertJsonFragment(['support_email' => 'help@mono.example']);
});

it('lets support read the catalog but not change it', function () {
    $id = ($this->create)()->json('data.id');
    forgetGuards();
    $support = userWithRoles(RoleSlug::Support);

    asStaff($support)->getJson('/api/v1/admin/apps')->assertOk();
    $this->getJson("/api/v1/admin/apps/{$id}")->assertOk();
    $this->patchJson("/api/v1/admin/apps/{$id}", ['visibility' => 'PUBLISHED'])->assertForbidden();
    $this->postJson('/api/v1/admin/categories', ['title' => 'X'])->assertForbidden();
});
