<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppScreenshot;
use App\Models\CatalogApp;
use App\Services\Audit\AuditService;
use App\Services\Catalog\CatalogImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AppMediaController extends Controller
{
    public function __construct(
        private readonly CatalogImageService $images,
        private readonly AuditService $audit,
    ) {}

    public function icon(Request $request, string $app): JsonResponse
    {
        $request->validate(['icon' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192']]);
        $model = $this->find($app);

        $stored = $this->images->storeIcon($request->file('icon'), $model->public_id);
        $this->images->delete($model->icon_path);
        $model->forceFill(['icon_path' => $stored['path']])->save();
        $this->audit->record('app.icon_changed', $model);

        return ApiResponse::ok(['icon_url' => $model->iconUrl()]);
    }

    public function banner(Request $request, string $app): JsonResponse
    {
        $request->validate(['banner' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:12288']]);
        $model = $this->find($app);

        $stored = $this->images->storeBanner($request->file('banner'), $model->public_id);
        $this->images->delete($model->banner_path);
        $model->forceFill(['banner_path' => $stored['path']])->save();
        $this->audit->record('app.banner_changed', $model);

        return ApiResponse::ok(['banner_url' => $model->bannerUrl()]);
    }

    public function destroyBanner(string $app): JsonResponse
    {
        $model = $this->find($app);

        $this->images->delete($model->banner_path);
        $model->forceFill(['banner_path' => null])->save();
        $this->audit->record('app.banner_removed', $model);

        return ApiResponse::ok(['banner_url' => null]);
    }

    public function storeScreenshot(Request $request, string $app): JsonResponse
    {
        $request->validate(['screenshot' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192']]);
        $model = $this->find($app);

        if ($model->screenshots()->count() >= (int) config('storefront.catalog.screenshot_max_count')) {
            throw new ApiException(ErrorCode::ValidationFailed, 'Достигнуто максимальное число скриншотов.', ['fields' => ['screenshot' => ['Не больше '.config('storefront.catalog.screenshot_max_count').' скриншотов.']]]);
        }

        $stored = $this->images->storeScreenshot($request->file('screenshot'), $model->public_id);
        $screenshot = $model->screenshots()->create($stored + ['sort_order' => (int) $model->screenshots()->max('sort_order') + 1]);
        $this->audit->record('app.screenshot_added', $model, after: ['screenshot' => $screenshot->public_id]);

        return ApiResponse::ok($screenshot->present(), 201);
    }

    public function destroyScreenshot(string $app, string $screenshot): JsonResponse
    {
        $model = $this->find($app);
        $record = $model->screenshots()->where('public_id', strtolower($screenshot))->firstOrFail();

        $this->images->delete($record->path);
        $record->delete();
        $this->audit->record('app.screenshot_removed', $model, before: ['screenshot' => $record->public_id]);

        return ApiResponse::ok(null);
    }

    public function reorderScreenshots(Request $request, string $app): JsonResponse
    {
        $data = $request->validate(['order' => ['required', 'array'], 'order.*' => ['string']]);
        $model = $this->find($app);
        $ids = array_map('strtolower', $data['order']);

        DB::transaction(function () use ($model, $ids) {
            foreach ($model->screenshots as $screenshot) {
                $position = array_search($screenshot->public_id, $ids, true);
                $screenshot->forceFill(['sort_order' => $position === false ? 999 : $position])->save();
            }
        });
        $this->audit->record('app.screenshots_reordered', $model);

        return ApiResponse::ok($model->screenshots()->get()->map(fn (AppScreenshot $screenshot) => $screenshot->present())->all());
    }

    private function find(string $publicId): CatalogApp
    {
        return CatalogApp::withTrashed()->where('public_id', strtolower($publicId))->firstOrFail();
    }
}
