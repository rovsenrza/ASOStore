<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Device;
use App\Models\User;
use App\Services\Devices\UdidHasher;
use App\Services\TelegramStore\AccountContacts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Customer list: every account with the end of its access (the latest ACTIVE subscription),
 * how much time is left (soonest first, with counts per window) and its iPhones. Admins see
 * the full UDID; others the last four characters.
 */
class SubscriptionMonitorController extends Controller
{
    public const STATES = ['active', 'ending1', 'ending3', 'ending7', 'expired7', 'expired', 'unlimited', 'none', 'all'];

    public function index(Request $request, AccountContacts $contacts, UdidHasher $hasher): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'in:'.implode(',', self::STATES)],
            'role' => ['nullable', 'string', 'max:40'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $now = now();
        $state = $filters['state'] ?? 'active';

        // Accounts that never had a subscription only show under «none» and «all».
        $join = in_array($state, ['none', 'all'], true) ? 'leftJoinSub' : 'joinSub';
        $query = User::query()
            ->{$join}($this->ends(), 's', 's.user_id', '=', 'users.id')
            ->select('users.*', 's.ends_at as access_ends_at', 's.unlimited as access_unlimited', 's.started_at as access_started_at')
            ->with(['roles', 'devices' => fn ($devices) => $devices->latest('id')->with('latestRegistration.team')])
            ->withCount('devices');

        if (filled($filters['q'] ?? null)) {
            $q = trim($filters['q']);
            $term = '%'.addcslashes($q, '%_\\').'%';
            $telegramUsers = str_starts_with($q, '@') || ctype_digit($q) ? $contacts->usersForTelegram($q)->all() : [];
            $deviceUsers = $this->usersForDevice($q, $hasher);
            $query->where(fn (Builder $where) => $where->where('users.name', 'like', $term)->orWhere('users.email', 'like', $term)
                ->when(ctype_digit($q), fn (Builder $id) => $id->orWhere('users.id', (int) $q))
                ->when($telegramUsers !== [], fn (Builder $tg) => $tg->orWhereIn('users.id', $telegramUsers))
                ->when($deviceUsers !== [], fn (Builder $dev) => $dev->orWhereIn('users.id', $deviceUsers)));
        }
        if (filled($filters['role'] ?? null)) {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('slug', $filters['role']));
        }

        $this->applyState($query, $state, $now);
        match (true) {
            // The whole base and never-paid accounts: newest first, like a sign-up feed.
            in_array($state, ['all', 'none'], true) => $query->orderByDesc('users.id'),
            in_array($state, ['expired', 'expired7'], true) => $query->orderByDesc('s.ends_at')->orderBy('users.id'),
            default => $query->orderBy('s.unlimited')->orderBy('s.ends_at')->orderBy('users.id'),
        };

        $page = $query->paginate($filters['per_page'] ?? 50);

        $showUdid = $request->user()?->can('devices.reveal-udid') ?? false;
        $rows = collect($page->items())->map(function (User $user) use ($contacts, $now, $showUdid) {
            $unlimited = (int) $user->getAttribute('access_unlimited') > 0;
            $end = $unlimited || $user->getAttribute('access_ends_at') === null ? null : Carbon::parse($user->getAttribute('access_ends_at'), 'UTC');

            return [
                'id' => $user->public_id,
                'number' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
                'status' => $user->status->value ?? $user->status,
                'roles' => $user->roleSlugs(),
                'devices' => (int) $user->devices_count,
                'device_list' => $user->devices->take(4)->map(fn (Device $device) => self::device($device, $showUdid))->values()->all(),
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

    /**
     * A device as one line in the customer list and card: model, identifier and where its
     * Apple registration stands.
     *
     * @return array<string, mixed>
     */
    public static function device(Device $device, bool $showUdid): array
    {
        $registration = $device->latestRegistration;

        return [
            'id' => $device->public_id,
            'product' => $device->product,
            'name' => $device->name,
            'os_version' => $device->os_version,
            'udid_hint' => $device->maskedUdid(),
            'udid' => $showUdid ? $device->udid_encrypted : null,
            'registration' => $registration?->status->value,
            'team' => $registration?->team?->apple_team_id,
        ];
    }

    /**
     * Accounts that own the device a search names: a full UDID (exact, by its hash) or its
     * last four characters.
     *
     * @return list<int>
     */
    private function usersForDevice(string $q, UdidHasher $hasher): array
    {
        $compact = preg_replace('/[^0-9A-Za-z]/', '', $q) ?? '';
        if (strlen($compact) >= 20) {
            return Device::query()->where('udid_hash', $hasher->hash($q))->pluck('user_id')->all();
        }
        if (strlen($q) === 4 && preg_match('/^[0-9A-Fa-f]{4}$/', $q)) {
            return Device::query()->where('udid_hint', strtoupper($q))->pluck('user_id')->unique()->values()->all();
        }

        return [];
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
            'none' => $query->whereNull('s.user_id'),
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

        $counts = collect(self::STATES)->reject(fn (string $state) => in_array($state, ['none', 'all'], true))
            ->mapWithKeys(fn (string $state) => [$state => $count($state)])->all();
        $counts['all'] = User::query()->count();
        $counts['none'] = User::query()->whereNotExists(fn (QueryBuilder $sub) => $sub->selectRaw('1')->from('subscriptions')
            ->whereColumn('subscriptions.user_id', 'users.id')->where('status', 'ACTIVE'))->count();

        return $counts;
    }
}
