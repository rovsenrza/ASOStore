<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppVersion;
use App\Models\CatalogApp;
use App\Services\Audit\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Versions and release notes. Uploaded IPAs create versions automatically
 * (Phase 5); operators write the release notes.
 */
class AppVersionController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function store(Request $request, string $app): JsonResponse
    {
        $model = CatalogApp::withTrashed()->where('public_id', strtolower($app))->firstOrFail();
        $data = $request->validate([
            'version' => ['required', 'string', 'max:32', 'regex:/^\d+(\.\d+){0,3}$/'],
            'build_number' => ['required', 'string', 'max:32'],
            'min_ios_version' => ['nullable', 'string', 'max:16', 'regex:/^\d+(\.\d+){0,2}$/'],
            'release_notes' => ['nullable', 'string', 'max:4000'],
            'released_at' => ['nullable', 'date'],
        ]);

        if ($model->versions()->where(['version' => $data['version'], 'build_number' => $data['build_number']])->exists()) {
            throw new ApiException(ErrorCode::VersionExists);
        }

        $version = $model->versions()->create($data + ['released_at' => $data['released_at'] ?? now()]);
        $this->audit->record('app.version_created', $model, after: ['version' => $version->version, 'build_number' => $version->build_number]);

        return ApiResponse::ok($this->present($version), 201);
    }

    public function update(Request $request, AppVersion $version): JsonResponse
    {
        $data = $request->validate([
            'release_notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'min_ios_version' => ['sometimes', 'nullable', 'string', 'max:16', 'regex:/^\d+(\.\d+){0,2}$/'],
            'released_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $version->update($data);
        $this->audit->record('app.version_updated', $version->app()->withTrashed()->firstOrFail(), after: ['version' => $version->version] + array_keys($data));

        return ApiResponse::ok($this->present($version));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AppVersion $version): array
    {
        return [
            'id' => $version->public_id,
            'version' => $version->version,
            'build_number' => $version->build_number,
            'min_ios_version' => $version->min_ios_version,
            'release_notes' => $version->release_notes,
            'released_at' => $version->released_at?->toIso8601ZuluString(),
        ];
    }
}
