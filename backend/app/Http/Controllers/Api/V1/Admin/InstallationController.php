<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\InstallationStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Installation;
use App\Models\InstallationEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Installation timelines for support and operators (FULL_PLAN §14, IMPLEMENTATION_PLAN §5.8).
 */
class InstallationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(InstallationStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Installation::query()->with(['app', 'user', 'device', 'artifact', 'signedBuild'])->latest('id');
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (filled($filters['q'] ?? null)) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn (Builder $where) => $where
                ->whereHas('user', fn (Builder $user) => $user->where('email', 'like', $term))
                ->orWhereHas('app', fn (Builder $app) => $app->where('name', 'like', $term)));
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($page, array_map(fn (Installation $installation) => $this->summary($installation), $page->items()));
    }

    public function show(Request $request, Installation $installation): JsonResponse
    {
        $installation->load(['app', 'user', 'device', 'artifact', 'signedBuild', 'events']);
        $audit = AuditLog::query()->where('subject_type', 'installation')->where('subject_id', $installation->public_id)->latest('id')->limit(50)->get();

        return ApiResponse::ok($this->summary($installation) + [
            'signed_build' => $installation->signedBuild ? [
                'id' => $installation->signedBuild->public_id,
                'status' => $installation->signedBuild->status->value,
                'status_reason' => $installation->signedBuild->status_reason,
                'sha256' => $installation->signedBuild->sha256,
                'expires_at' => $installation->signedBuild->expires_at?->toIso8601ZuluString(),
            ] : null,
            'events' => $installation->events->map(fn (InstallationEvent $event) => [
                'type' => $event->type,
                'at' => $event->created_at->toIso8601ZuluString(),
                'request_id' => $event->request_id,
                'meta' => $event->meta,
            ])->all(),
            'history' => $audit->map(fn (AuditLog $entry) => (new AuditLogResource($entry))->resolve($request))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Installation $installation): array
    {
        return [
            'id' => $installation->public_id,
            'status' => $installation->status->value,
            'status_reason' => $installation->status_reason,
            'app' => ['id' => $installation->app->public_id, 'name' => $installation->app->name],
            'user' => ['id' => $installation->user->public_id, 'email' => $installation->user->email],
            'device' => ['id' => $installation->device->public_id, 'udid_hint' => $installation->device->maskedUdid()],
            'version' => $installation->artifact->version,
            'build_number' => $installation->artifact->build_number,
            'delivered_at' => $installation->delivered_at?->toIso8601ZuluString(),
            'created_at' => $installation->created_at?->toIso8601ZuluString(),
            'updated_at' => $installation->updated_at?->toIso8601ZuluString(),
        ];
    }
}
