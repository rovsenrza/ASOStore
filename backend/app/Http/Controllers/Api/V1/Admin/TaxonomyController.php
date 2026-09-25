<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\CategoryKind;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppCategory;
use App\Models\AppPublisher;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Categories and publishers for catalog listings.
 */
class TaxonomyController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function categories(): JsonResponse
    {
        return ApiResponse::ok(AppCategory::query()->withCount('apps')->orderBy('sort_order')->orderBy('title')->get()
            ->map(fn (AppCategory $category) => $this->presentCategory($category))->all());
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:64'],
            'subtitle' => ['nullable', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:64', 'alpha_dash', Rule::unique('app_categories', 'slug')],
            'kind' => ['nullable', Rule::enum(CategoryKind::class)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
        $data['kind'] ??= CategoryKind::Apps->value;
        $category = AppCategory::create($data + ['slug' => $data['slug'] ?? (Str::slug($data['title'], language: 'ru') ?: Str::lower(Str::random(8)))]);
        $this->audit->record('category.created', $category, after: ['title' => $category->title]);

        return ApiResponse::ok($this->presentCategory($category->loadCount('apps')), 201);
    }

    public function updateCategory(Request $request, AppCategory $category): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:64'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:120'],
            'kind' => ['sometimes', Rule::enum(CategoryKind::class)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ]);
        $category->update($data);
        $this->audit->record('category.updated', $category, after: $data);

        return ApiResponse::ok($this->presentCategory($category->loadCount('apps')));
    }

    public function publishers(): JsonResponse
    {
        return ApiResponse::ok(AppPublisher::query()->withCount('apps')->orderBy('name')->get()
            ->map(fn (AppPublisher $publisher) => $this->presentPublisher($publisher))->all());
    }

    public function storePublisher(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('app_publishers', 'name')],
            'website' => ['nullable', 'url:https', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
        ]);
        $publisher = AppPublisher::create($data);
        $this->audit->record('publisher.created', $publisher, after: ['name' => $publisher->name]);

        return ApiResponse::ok($this->presentPublisher($publisher->loadCount('apps')), 201);
    }

    public function updatePublisher(Request $request, AppPublisher $publisher): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', Rule::unique('app_publishers', 'name')->ignore($publisher->id)],
            'website' => ['sometimes', 'nullable', 'url:https', 'max:255'],
            'support_email' => ['sometimes', 'nullable', 'email', 'max:255'],
        ]);
        $publisher->update($data);
        $this->audit->record('publisher.updated', $publisher, after: $data);

        return ApiResponse::ok($this->presentPublisher($publisher->loadCount('apps')));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCategory(AppCategory $category): array
    {
        return [
            'id' => $category->public_id,
            'slug' => $category->slug,
            'title' => $category->title,
            'subtitle' => $category->subtitle,
            'kind' => $category->kind->value,
            'sort_order' => $category->sort_order,
            'app_count' => $category->apps_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPublisher(AppPublisher $publisher): array
    {
        return [
            'id' => $publisher->public_id,
            'name' => $publisher->name,
            'website' => $publisher->website,
            'support_email' => $publisher->support_email,
            'app_count' => $publisher->apps_count,
        ];
    }
}
