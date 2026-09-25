<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ErrorCode;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Auth\TokenService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TokenService $tokens,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(RoleSlug::class)],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = User::query()->with(AdminUserResource::RELATIONS)->latest('id');

        if (filled($filters['q'] ?? null)) {
            $term = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn (Builder $where) => $where->where('name', 'like', $term)->orWhere('email', 'like', $term));
        }
        if (filled($filters['role'] ?? null)) {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('slug', $filters['role']));
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($page, AdminUserResource::collection($page->items())->resolve($request));
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $user->load([...AdminUserResource::RELATIONS, 'subscriptions' => fn ($query) => $query->latest('id')]);

        $history = AuditLog::query()
            ->where(fn (Builder $where) => $where
                ->where(['subject_type' => 'user', 'subject_id' => $user->public_id])
                ->orWhere(fn (Builder $actor) => $actor->where('actor_type', 'user')->where('actor_id', $user->id)))
            ->latest('id')
            ->limit(20)
            ->get();

        return ApiResponse::ok((new AdminUserResource($user))->resolve($request) + [
            'subscriptions' => SubscriptionResource::collection($user->subscriptions)->resolve($request),
            'recent_activity' => $history->map(fn (AuditLog $entry) => (new AuditLogResource($entry, [$user->id => $user->public_id]))->resolve($request))->all(),
        ]);
    }

    /**
     * Creates an account (usually staff) and emails a link to set its password.
     */
    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [Rule::enum(RoleSlug::class)],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Str::password(40),
            ]);
            $user->roles()->attach(Role::query()->whereIn('slug', $data['roles'])->pluck('id'), ['granted_by' => auth()->id()]);
            $this->audit->record('user.created', $user, after: ['email' => $user->email, 'roles' => array_values($data['roles'])]);

            return $user;
        });

        Password::broker()->sendResetLink(['email' => $user->email]);

        return ApiResponse::ok((new AdminUserResource($user->load(AdminUserResource::RELATIONS)))->resolve($request), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(UserStatus::class)],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $status = UserStatus::from($data['status']);

        if ($user->is($request->user()) && $status === UserStatus::Suspended) {
            throw new ApiException(ErrorCode::Conflict, 'Нельзя заблокировать собственный аккаунт.');
        }

        $before = $user->status;
        DB::transaction(function () use ($user, $status, $before, $data) {
            $user->forceFill(['status' => $status])->save();
            if ($status === UserStatus::Suspended) {
                $this->tokens->revokeAllForUser($user);
            }
            $this->audit->record('user.status_changed', $user, ['status' => $before->value], ['status' => $status->value], $data['reason']);
        });

        return ApiResponse::ok((new AdminUserResource($user->load(AdminUserResource::RELATIONS)))->resolve($request));
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => [Rule::enum(RoleSlug::class)],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $roles = array_values(array_unique($data['roles']));

        if ($user->is($request->user()) && ! in_array(RoleSlug::Admin->value, $roles, true)) {
            throw new ApiException(ErrorCode::Conflict, 'Нельзя снять роль администратора с самого себя.');
        }

        $before = $user->roleSlugs();
        DB::transaction(function () use ($user, $roles, $before, $data) {
            $ids = Role::query()->whereIn('slug', $roles)->pluck('id');
            $user->roles()->syncWithPivotValues($ids, ['granted_by' => auth()->id()]);
            $user->unsetRelation('roles');
            sort($roles);
            $this->audit->record('user.roles_changed', $user, ['roles' => $before], ['roles' => $roles], $data['reason']);
        });

        return ApiResponse::ok((new AdminUserResource($user->load(AdminUserResource::RELATIONS)))->resolve($request));
    }

    /**
     * For a lost authenticator: the user enrols again at the next admin sign-in.
     */
    public function resetTotp(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        DB::transaction(function () use ($user, $data) {
            $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->audit->record('user.totp_reset', $user, reason: $data['reason']);
        });

        return ApiResponse::ok((new AdminUserResource($user->load(AdminUserResource::RELATIONS)))->resolve($request));
    }
}
