<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ActorType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:96'],
            'subject_type' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'string', 'max:64'],
            'actor_id' => ['nullable', 'string', 'max:26'],
            'request_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditLog::query()->latest('id');

        if (filled($filters['action'] ?? null)) {
            // "artifact." matches every artifact event; a full name matches exactly.
            $query->where('action', 'like', addcslashes($filters['action'], '%_\\').'%');
        }
        foreach (['subject_type', 'subject_id', 'request_id'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }
        if (filled($filters['actor_id'] ?? null)) {
            $query->where('actor_type', 'user')->where('actor_id', User::query()->where('public_id', strtolower($filters['actor_id']))->value('id') ?? 0);
        }
        if (filled($filters['from'] ?? null)) {
            $query->where('occurred_at', '>=', $filters['from']);
        }
        if (filled($filters['to'] ?? null)) {
            $query->where('occurred_at', '<=', $filters['to']);
        }

        $page = $query->paginate($filters['per_page'] ?? 50);

        $actorIds = collect($page->items())
            ->filter(fn (AuditLog $entry) => $entry->actor_type === ActorType::User && $entry->actor_id !== null)
            ->pluck('actor_id')
            ->unique();
        $publicIds = User::query()->whereIn('id', $actorIds)->pluck('public_id', 'id')->all();

        return ApiResponse::paginated($page, array_map(
            fn (AuditLog $entry) => (new AuditLogResource($entry, $publicIds))->resolve($request),
            $page->items(),
        ));
    }
}
