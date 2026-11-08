<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AppleTeam;
use App\Models\DeviceRegistration;
use App\Models\SupportTicket;
use App\Models\TelegramStoreOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One screen with the numbers an operator checks every day: devices and how long Apple keeps
 * them waiting, load per team, subscriptions running out, sales and open tickets.
 */
class OverviewController extends Controller
{
    public function show(): JsonResponse
    {
        $now = now();

        return ApiResponse::ok([
            'generated_at' => $now->toIso8601ZuluString(),
            'devices' => $this->devices(),
            'waiting' => $this->waiting($now),
            'teams' => $this->teams(),
            'subscriptions' => $this->subscriptions($now),
            'sales' => $this->sales($now),
            'tickets_open' => SupportTicket::query()->where('status', 'OPEN')->count(),
        ]);
    }

    /** Devices by the status of their latest registration. */
    private function devices(): array
    {
        $latest = DB::table('device_registrations')->selectRaw('max(id) as id')->groupBy('device_id');
        $byStatus = DB::table('device_registrations as r')
            ->joinSub($latest, 'l', 'l.id', '=', 'r.id')
            ->selectRaw('r.status, count(*) as n')->groupBy('r.status')->pluck('n', 'status')
            ->map(fn ($n) => (int) $n)->all();

        return [
            'total' => DB::table('devices')->count(),
            'enrolled' => DB::table('devices')->whereNotNull('enrolled_at')->count(),
            'by_status' => $byStatus,
        ];
    }

    /**
     * Customers still waiting for Apple: pending registrations of devices that have no usable
     * (ELIGIBLE on an ACTIVE team) registration anywhere.
     */
    private function waiting(Carbon $now): array
    {
        $rows = DeviceRegistration::query()
            ->with(['device.user', 'team'])
            ->where('status', 'APPLE_PENDING')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('device_registrations as e')
                ->join('apple_teams as t', 't.id', '=', 'e.apple_team_id')
                ->whereColumn('e.device_id', 'device_registrations.device_id')
                ->where('e.status', 'ELIGIBLE')->where('t.status', 'ACTIVE'))
            ->orderBy('created_at')
            ->get();

        $buckets = ['under_1d' => 0, '1_3d' => 0, '3_7d' => 0, '7_14d' => 0, 'over_14d' => 0];
        foreach ($rows as $registration) {
            $hours = $registration->created_at->diffInHours($now, true);
            $key = match (true) {
                $hours < 24 => 'under_1d',
                $hours < 72 => '1_3d',
                $hours < 168 => '3_7d',
                $hours < 336 => '7_14d',
                default => 'over_14d',
            };
            $buckets[$key]++;
        }

        return [
            'total' => $rows->count(),
            'buckets' => $buckets,
            'longest' => $rows->take(25)->map(fn (DeviceRegistration $registration) => [
                'registration_id' => $registration->public_id,
                'device_id' => $registration->device?->public_id,
                'device' => $registration->device?->product ?: $registration->device?->name,
                'user' => $registration->device?->user ? [
                    'id' => $registration->device->user->public_id,
                    'number' => $registration->device->user->id,
                    'email' => $registration->device->user->email,
                    'name' => $registration->device->user->name,
                ] : null,
                'team' => $registration->team?->apple_team_id,
                'reason' => $registration->status_reason,
                'since' => $registration->created_at->toIso8601ZuluString(),
                'hours' => round($registration->created_at->diffInMinutes($now, true) / 60, 1),
            ])->values()->all(),
        ];
    }

    /** Load per team for the current membership year. */
    private function teams(): array
    {
        $counts = DB::table('device_registrations as r')
            ->join('membership_years as y', 'y.id', '=', 'r.membership_year_id')
            ->where('y.status', 'ACTIVE')->where('y.starts_at', '<=', now())->where('y.ends_at', '>', now())
            ->selectRaw("r.apple_team_id,
                sum(case when r.apple_device_id is not null then 1 else 0 end) as slots,
                sum(case when r.status = 'ELIGIBLE' then 1 else 0 end) as eligible,
                sum(case when r.status = 'APPLE_PENDING' then 1 else 0 end) as pending,
                sum(case when r.status = 'DISABLED' then 1 else 0 end) as disabled")
            ->groupBy('r.apple_team_id')->get()->keyBy('apple_team_id');

        return AppleTeam::query()->orderBy('id')->get()->map(fn (AppleTeam $team) => [
            'id' => $team->public_id,
            'apple_team_id' => $team->apple_team_id,
            'name' => $team->name,
            'status' => $team->status->value,
            'is_primary' => (bool) $team->is_primary,
            'slots_used' => (int) ($counts[$team->id]->slots ?? 0),
            'eligible' => (int) ($counts[$team->id]->eligible ?? 0),
            'pending' => (int) ($counts[$team->id]->pending ?? 0),
            'disabled' => (int) ($counts[$team->id]->disabled ?? 0),
        ])->all();
    }

    /** Customers by when their last subscription ends. */
    private function subscriptions(Carbon $now): array
    {
        $ends = DB::table('subscriptions')->where('status', 'ACTIVE')
            ->selectRaw('user_id, max(ends_at) as ends_at, sum(case when ends_at is null then 1 else 0 end) as unlimited')
            ->groupBy('user_id')->get();

        $count = fn (callable $test) => $ends->filter(fn ($row) => (int) $row->unlimited === 0 && $test(Carbon::parse($row->ends_at, 'UTC')))->count();

        return [
            'customers' => $ends->count(),
            'active' => $ends->filter(fn ($row) => (int) $row->unlimited > 0 || Carbon::parse($row->ends_at, 'UTC')->isAfter($now))->count(),
            'unlimited' => $ends->filter(fn ($row) => (int) $row->unlimited > 0)->count(),
            'ending_24h' => $count(fn (Carbon $end) => $end->isAfter($now) && $end->lte($now->copy()->addDay())),
            'ending_3d' => $count(fn (Carbon $end) => $end->isAfter($now) && $end->lte($now->copy()->addDays(3))),
            'ending_7d' => $count(fn (Carbon $end) => $end->isAfter($now) && $end->lte($now->copy()->addDays(7))),
            'ended_7d' => $count(fn (Carbon $end) => $end->lte($now) && $end->isAfter($now->copy()->subDays(7))),
            'ended_total' => $count(fn (Carbon $end) => $end->lte($now)),
        ];
    }

    private function sales(Carbon $now): array
    {
        $paid = fn () => TelegramStoreOrder::query()->where('status', TelegramStoreOrder::PAID)
            ->where(fn ($query) => $query->whereNull('payment_provider')->orWhere('payment_provider', '!=', 'mock'));
        $window = fn (Carbon $since) => [
            'orders' => $paid()->where('paid_at', '>=', $since)->count(),
            'revenue_rub' => (int) $paid()->where('paid_at', '>=', $since)->sum('amount_due_rub'),
        ];

        return [
            'today' => $window($now->copy()->startOfDay()),
            'last_24h' => $window($now->copy()->subDay()),
            'last_7d' => $window($now->copy()->subDays(7)),
            'last_30d' => $window($now->copy()->subDays(30)),
            'awaiting_review' => TelegramStoreOrder::query()->where('status', TelegramStoreOrder::REVIEW)->count(),
        ];
    }
}
