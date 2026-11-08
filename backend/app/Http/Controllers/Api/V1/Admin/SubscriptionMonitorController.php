<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\TelegramStore\AccountContacts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Subscription monitor: every customer with the end of their access (the latest ACTIVE
 * subscription) and how much time is left, soonest first, with counts per window.
 */
class SubscriptionMonitorController extends Controller
{
    public const STATES = ['active', 'ending1', 'ending3', 'ending7', 'expired7', 'expired', 'unlimited', 'all'];

    public function index(Request $request, AccountContacts $contacts): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'in:'.implode(',', self::STATES)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $now = now();
        $state = $filters['state'] ?? 'active';

        $query = User::query()
            ->joinSub($this->ends(), 's', 's.user_id', '=', 'users.id')
            ->select('users.*', 's.ends_at as access_ends_at', 's.unlimited as access_unlimited', 's.started_at as access_started_at')
            ->withCount('devices');

        if (filled($filters['q'] ?? null)) {
            $q = trim($filters['q']);
            $term = '%'.addcslashes($q, '%_\\').'%';
            $telegramUsers = str_starts_with($q, '@') || ctype_digit($q) ? $contacts->usersForTelegram($q)->all() : [];
            $query->where(fn (Builder $where) => $where->where('users.name', 'like', $term)->orWhere('users.email', 'like', $term)
                ->when(ctype_digit($q), fn (Builder $id) => $id->orWhere('users.id', (int) $q))
                ->when($telegramUsers !== [], fn (Builder $tg) => $tg->orWhereIn('users.id', $telegramUsers)));
        }

        $this->applyState($query, $state, $now);
        in_array($state, ['expired', 'expired7'], true)
            ? $query->orderByDesc('s.ends_at')
            : $query->orderBy('s.unlimited')->orderBy('s.ends_at');
        $query->orderBy('users.id');

        $page = $query->paginate($filters['per_page'] ?? 50);

        $rows = collect($page->items())->map(function (User $user) use ($contacts, $now) {
            $unlimited = (int) $user->getAttribute('access_unlimited') > 0;
            $end = $unlimited || $user->getAttribute('access_ends_at') === null ? null : Carbon::parse($user->getAttribute('access_ends_at'), 'UTC');

            return [
                'id' => $user->public_id,
                'number' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'status' => $user->status->value ?? $user->status,
                'devices' => (int) $user->devices_count,
                'started_at' => $user->getAttribute('access_started_at') ? Carbon::parse($user->getAttribute('access_started_at'), 'UTC')->toIso8601ZuluString() : null,
                'ends_at' => $end?->toIso8601ZuluString(),
                'unlimited' => $unlimited,
                'seconds_left' => $end === null ? null : (int) $now->diffInSeconds($end, false),
                'telegram' => $contacts->telegram($user)->first(),
            ];
        })->all();

        return ApiResponse::ok($rows, meta: [
            'pagination' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'summary' => $this->summary($now),
            'now' => $now->toIso8601ZuluString(),
        ]);
    }

    /** One row per customer: when their access ends (latest ACTIVE subscription). */
    private function ends(): QueryBuilder
    {
        return DB::table('subscriptions')->where('status', 'ACTIVE')
            ->selectRaw('user_id, max(ends_at) as ends_at, max(case when ends_at is null then 1 else 0 end) as unlimited, min(starts_at) as started_at')
            ->groupBy('user_id');
    }

    private function applyState(Builder $query, string $state, Carbon $now): void
    {
        $limited = fn (Builder $q) => $q->where('s.unlimited', 0);
        match ($state) {
            'active' => $query->where(fn (Builder $q) => $q->where('s.unlimited', '>', 0)->orWhere('s.ends_at', '>', $now)),
            'ending1' => $limited($query)->where('s.ends_at', '>', $now)->where('s.ends_at', '<=', $now->copy()->addDay()),
            'ending3' => $limited($query)->where('s.ends_at', '>', $now)->where('s.ends_at', '<=', $now->copy()->addDays(3)),
            'ending7' => $limited($query)->where('s.ends_at', '>', $now)->where('s.ends_at', '<=', $now->copy()->addDays(7)),
            'expired7' => $limited($query)->where('s.ends_at', '<=', $now)->where('s.ends_at', '>', $now->copy()->subDays(7)),
            'expired' => $limited($query)->where('s.ends_at', '<=', $now),
            'unlimited' => $query->where('s.unlimited', '>', 0),
            default => null,
        };
    }

    /** @return array<string, int> */
    private function summary(Carbon $now): array
    {
        $count = function (string $state) use ($now) {
            $query = User::query()->joinSub($this->ends(), 's', 's.user_id', '=', 'users.id');
            $this->applyState($query, $state, $now);

            return $query->count();
        };

        return collect(self::STATES)->mapWithKeys(fn (string $state) => [$state => $count($state)])->all();
    }
}
