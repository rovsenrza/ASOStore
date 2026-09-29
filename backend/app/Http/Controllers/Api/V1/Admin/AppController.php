<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AppVisibility;
use App\Enums\ErrorCode;
use App\Enums\SourceType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminAppResource;
use App\Http\Responses\ApiResponse;
use App\Models\AppCategory;
use App\Models\AppleTeam;
use App\Models\AppPublisher;
use App\Models\CatalogApp;
use App\Services\Audit\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Catalog listings for operators (IMPLEMENTATION_PLAN P4-BE-01). Listings
 * are soft-deleted only (FULL_PLAN §7); every change is audited.
 */
class AppController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'visibility' => ['nullable', Rule::enum(AppVisibility::class)],
            'category' => ['nullable', 'string', 'max:64'],
            'deleted' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = CatalogApp::query()->with(AdminAppResource::RELATIONS)->orderBy('name');
        if ($request->boolean('deleted')) {
            $query->onlyTrashed();
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn (Builder $where) => $where->where('name', 'like', $term)->orWhere('bundle_identifier', 'like', $term)->orWhere('slug', 'like', $term));
        }
        if (filled($filters['visibility'] ?? null)) {
            $query->where('visibility', $filters['visibility']);
        }
        if (filled($filters['category'] ?? null)) {
            $query->whereHas('category', fn (Builder $category) => $category->where('slug', $filters['category']));
        }

        $page = $query->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($page, array_map(fn (CatalogApp $app) => (new AdminAppResource($app))->resolve($request), $page->items()));
    }

    public function show(Request $request, string $app): JsonResponse
    {
        return ApiResponse::ok($this->detail($request, $this->find($app)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(creating: true));

        $app = CatalogApp::create($this->attributes($data) + [
            'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
            'visibility' => $data['visibility'] ?? AppVisibility::Draft->value,
        ]);
        $this->audit->record('app.created', $app, after: ['name' => $app->name, 'visibility' => $app->visibility->value, 'source_type' => $app->source_type->value]);

        return ApiResponse::ok($this->detail($request, $app), 201);
    }

    public function update(Request $request, string $app): JsonResponse
    {
        $model = $this->find($app);
        $data = $request->validate($this->rules(creating: false, appId: $model->id));
        if (array_key_exists('is_storefront', $data) && ! $data['is_storefront']
            && AppleTeam::query()->where('storefront_app_id', $model->id)->exists()) {
            throw new ApiException(ErrorCode::Conflict, 'Вариант Ru AppStore назначен команде Apple.');
        }

        $model->fill($this->attributes($data));
        if (array_key_exists('visibility', $data)) {
            $model->visibility = AppVisibility::from($data['visibility']);
        }
        if (filled($data['slug'] ?? null) && $data['slug'] !== $model->slug) {
            $model->slug = $this->uniqueSlug($data['slug'], $model->id);
        }

        $changes = $model->getDirty();
        if ($changes !== []) {
            $before = array_intersect_key($model->getOriginal(), $changes);
            $model->save();
            $this->audit->record(
                array_key_exists('visibility', $changes) ? 'app.visibility_changed' : 'app.updated',
                $model,
                $this->scalars($before),
                $this->scalars(array_intersect_key($model->getAttributes(), $changes)),
                $data['reason'] ?? null,
            );
        }

        return ApiResponse::ok($this->detail($request, $model));
    }

    public function destroy(Request $request, string $app): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $model = $this->find($app);
        if (AppleTeam::query()->where('storefront_app_id', $model->id)->exists()) {
            throw new ApiException(ErrorCode::Conflict, 'Сначала отвяжите вариант Ru AppStore от команды Apple.');
        }
        $model->delete();
        $this->audit->record('app.deleted', $model, reason: $data['reason']);

        return ApiResponse::ok(null);
    }

    public function restore(Request $request, string $app): JsonResponse
    {
        $model = $this->find($app);
        $model->restore();
        $this->audit->record('app.restored', $model);

        return ApiResponse::ok($this->detail($request, $model));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating, ?int $appId = null): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:96', 'alpha_dash'],
            // Signed builds carry this ID instead of the IPA's (AppArtifact::signingBundleIdentifier).
            'bundle_identifier' => ['sometimes', 'nullable', 'string', 'max:155', 'regex:/^[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/',
                Rule::unique('apps', 'bundle_identifier')->ignore($appId)],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'category_id' => [$required, 'string', Rule::exists('app_categories', 'public_id')],
            'publisher_id' => [$required, 'string', Rule::exists('app_publishers', 'public_id')],
            'source_type' => [$required, Rule::enum(SourceType::class)],
            'visibility' => ['sometimes', Rule::enum(AppVisibility::class)],
            'is_storefront' => ['sometimes', 'boolean'],
            'age_rating' => ['sometimes', Rule::in(['4+', '9+', '12+', '17+'])],
            'featured_rank' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'support_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'privacy_url' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'subtitle', 'description', 'bundle_identifier', 'source_type', 'age_rating', 'featured_rank', 'support_url', 'privacy_url', 'is_storefront']));
        if (isset($data['category_id'])) {
            $attributes['category_id'] = AppCategory::query()->where('public_id', $data['category_id'])->value('id');
        }
        if (isset($data['publisher_id'])) {
            $attributes['publisher_id'] = AppPublisher::query()->where('public_id', $data['publisher_id'])->value('id');
        }

        return $attributes;
    }

    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source, language: 'ru') ?: 'app';
        $slug = $base;
        for ($i = 2; CatalogApp::withTrashed()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function find(string $publicId): CatalogApp
    {
        return CatalogApp::withTrashed()->where('public_id', strtolower($publicId))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Request $request, CatalogApp $app): array
    {
        $app->load([...AdminAppResource::RELATIONS, 'screenshots', 'versions' => fn ($query) => $query->orderByDesc('released_at')->orderByDesc('id')]);

        return (new AdminAppResource($app, detailed: true))->resolve($request);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scalars(array $values): array
    {
        return array_map(fn ($value) => $value instanceof \BackedEnum ? $value->value : $value, $values);
    }
}
