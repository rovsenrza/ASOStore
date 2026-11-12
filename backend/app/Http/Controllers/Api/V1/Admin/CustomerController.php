<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ErrorCode;
use App\Enums\SubscriptionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ActivationCode;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DeviceRegistration;
use App\Models\Installation;
use App\Models\Subscription;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\TelegramStore\AccountContacts;
use App\Services\TelegramStore\TelegramApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Everything support needs about one customer on one screen, plus the actions that used to
 * need a database console: change the subscription end and write to the buyer in Telegram.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AccountContacts $contacts,
    ) {}

    public function profile(Request $request, User $user): JsonResponse
    {
        $user->load(['roles', 'devices.registrations.team', 'subscriptions' => fn ($query) => $query->latest('id')]);
        $showUdid = $request->user()?->can('devices.reveal-udid') ?? false;

        $subscriptionIds = $user->subscriptions->pluck('public_id');
        $extensions = AuditLog::query()->where('subject_type', 'subscription')->whereIn('subject_id', $subscriptionIds->all())
            ->whereIn('action', ['subscription.extended', 'subscription.adjusted', 'subscription.corrected'])
            ->latest('id')->get();

        $codes = ActivationCode::query()->where('redeemed_by', $user->id)->latest('redeemed_at')->get();
        $telegram = $this->contacts->telegram($user);
        $customers = TelegramStoreCustomer::query()->whereIn('telegram_user_id', $telegram->pluck('telegram_user_id')->all())->get()->keyBy('telegram_user_id');

        $lastEnd = $user->subscriptions->where('status', SubscriptionStatus::Active)->max('ends_at');
        $unlimited = $user->subscriptions->where('status', SubscriptionStatus::Active)->contains(fn (Subscription $s) => $s->ends_at === null);

        return ApiResponse::ok([
            'id' => $user->public_id,
            'number' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status->value,
            'created_at' => $user->created_at?->toIso8601ZuluString(),
            'account' => [
                'roles' => $user->roleSlugs(),
                'totp_enabled' => $user->hasConfirmedTotp(),
                'last_login_at' => $user->last_login_at?->toIso8601ZuluString(),
                'erased_at' => $user->erased_at?->toIso8601ZuluString(),
            ],
            'access' => [
                'unlimited' => $unlimited,
                'ends_at' => $unlimited ? null : ($lastEnd ? Carbon::parse($lastEnd)->toIso8601ZuluString() : null),
                'active' => $unlimited || ($lastEnd !== null && Carbon::parse($lastEnd)->isFuture()),
                'days_left' => $unlimited || $lastEnd === null ? null : round(now()->diffInMinutes(Carbon::parse($lastEnd), false) / 1440, 1),
                'paid_days' => (int) $codes->filter(fn (ActivationCode $c) => $c->status->value === 'REDEEMED')->sum('duration_days'),
            ],
            'subscriptions' => $user->subscriptions->map(fn (Subscription $s) => [
                'id' => $s->public_id,
                'plan' => $s->plan,
                'status' => $s->status->value,
                'starts_at' => $s->starts_at->toIso8601ZuluString(),
                'ends_at' => $s->ends_at?->toIso8601ZuluString(),
            ])->all(),
            'extensions' => $extensions->map(fn (AuditLog $entry) => [
                'occurred_at' => $entry->occurred_at?->toIso8601ZuluString(),
                'action' => $entry->action,
                'actor' => $entry->actor_label,
                'before' => $entry->before['ends_at'] ?? null,
                'after' => $entry->after['ends_at'] ?? null,
                'days' => $entry->after['days'] ?? null,
                'delay_minutes' => $entry->after['delay_minutes'] ?? null,
                'reason' => $entry->reason,
            ])->all(),
            'codes' => $codes->map(fn (ActivationCode $code) => [
                'id' => $code->public_id,
                'hint' => $code->code_hint,
                'status' => $code->status->value,
                'duration_days' => $code->duration_days,
                'note' => $code->note,
                'redeemed_at' => $code->redeemed_at?->toIso8601ZuluString(),
            ])->all(),
            'devices' => $user->devices->sortByDesc('id')->map(fn (Device $device) => [
                'id' => $device->public_id,
                'udid_hint' => $device->maskedUdid(),
                'udid' => $showUdid ? $device->udid_encrypted : null,
                'product' => $device->product,
                'name' => $device->name,
                'os_version' => $device->os_version,
                'enrolled_at' => $device->enrolled_at?->toIso8601ZuluString(),
                'registrations' => $device->registrations->sortByDesc('id')->map(fn (DeviceRegistration $r) => self::registration($r))->values()->all(),
            ])->values()->all(),
            'orders' => $this->contacts->orders($user)->with('promoCode')->latest('id')->limit(50)->get()
                ->map(fn (TelegramStoreOrder $order) => StoreController::order($order))->all(),
            'telegram' => $telegram->map(fn (array $contact) => $contact + [
                'customer_id' => $customers[$contact['telegram_user_id']]->id ?? null,
                'balance_rub' => $customers[$contact['telegram_user_id']]->balance_rub ?? null,
                'blocked_bot' => isset($customers[$contact['telegram_user_id']]) && $customers[$contact['telegram_user_id']]->blocked_at !== null,
            ])->all(),
            'installations' => Installation::query()->where('user_id', $user->id)->with('app')->latest('id')->limit(20)->get()
                ->map(fn (Installation $installation) => [
                    'id' => $installation->public_id,
                    'app' => $installation->app?->name,
                    'status' => $installation->status->value,
                    'reason' => $installation->status_reason,
                    'created_at' => $installation->created_at?->toIso8601ZuluString(),
                    'delivered_at' => $installation->delivered_at?->toIso8601ZuluString(),
                ])->all(),
            'tickets' => DB::table('support_tickets')->where('user_id', $user->id)->latest('id')->limit(10)
                ->get(['public_id', 'topic', 'message', 'status', 'created_at'])
                ->map(fn ($ticket) => [
                    'id' => $ticket->public_id, 'topic' => $ticket->topic, 'message' => $ticket->message,
                    'status' => $ticket->status, 'created_at' => Carbon::parse($ticket->created_at)->toIso8601ZuluString(),
                ])->all(),
        ]);
    }

    /**
     * Changes when the customer's access ends: add or remove days, or set an exact end.
     * Works on the subscription that ends last; creates one when the customer has none.
     */
    public function adjustSubscription(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', 'in:add,set'],
            'days' => ['required_if:mode,add', 'nullable', 'numeric', 'between:-3650,3650', 'not_in:0'],
            'ends_at' => ['required_if:mode,set', 'nullable', 'date', 'before:2037-12-31'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $subscription = DB::transaction(function () use ($user, $data) {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $subscription = Subscription::query()->where('user_id', $user->id)->where('status', SubscriptionStatus::Active->value)
                ->orderByRaw('ends_at IS NULL DESC')->orderByDesc('ends_at')->lockForUpdate()->first();
            if ($subscription !== null && $subscription->ends_at === null) {
                throw new ApiException(ErrorCode::Conflict, 'У пользователя бессрочная подписка — менять нечего.');
            }

            $before = $subscription?->ends_at?->copy();
            if ($data['mode'] === 'set') {
                $end = Carbon::parse($data['ends_at'])->utc();
            } else {
                $minutes = (int) round(((float) $data['days']) * 1440);
                // Adding to an access that already ended starts from now, not from the old end.
                $base = $minutes > 0 && ($before === null || $before->isPast()) ? now() : ($before ?? now());
                $end = $base->copy()->addMinutes($minutes);
            }

            if ($subscription === null) {
                $subscription = Subscription::create([
                    'user_id' => $user->id, 'plan' => 'standard', 'status' => SubscriptionStatus::Active,
                    'starts_at' => now(), 'ends_at' => $end,
                ]);
            } else {
                $subscription->forceFill(['ends_at' => $end])->save();
            }

            $this->audit->record('subscription.adjusted', $subscription,
                before: ['ends_at' => $before?->utc()->format('Y-m-d\TH:i:s\Z')],
                after: ['ends_at' => $end->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'mode' => $data['mode'], 'days' => $data['mode'] === 'add' ? (float) $data['days'] : null],
                reason: $data['reason']);

            return $subscription;
        });

        return ApiResponse::ok([
            'id' => $subscription->public_id,
            'ends_at' => $subscription->ends_at?->toIso8601ZuluString(),
        ]);
    }

    /** Writes to the customer's Telegram (every bot buyer behind the account) from the store bot. */
    public function messageTelegram(Request $request, User $user, TelegramApi $api): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:3500']]);
        $contacts = $this->contacts->telegram($user);
        if ($contacts->isEmpty()) {
            throw new ApiException(ErrorCode::Conflict, 'У пользователя нет Telegram: он не покупал через бота.');
        }

        $sent = [];
        $failed = [];
        foreach ($contacts as $contact) {
            try {
                $api->sendMessage($contact['telegram_user_id'], e($data['text']));
                $sent[] = $contact['telegram_user_id'];
            } catch (Throwable $exception) {
                report($exception);
                $failed[] = $contact['telegram_user_id'];
            }
        }
        $this->audit->record('user.telegram_messaged', $user, after: ['sent' => $sent, 'failed' => $failed], reason: mb_substr($data['text'], 0, 500));

        if ($sent === []) {
            throw new ApiException(ErrorCode::Conflict, 'Telegram не доставил сообщение (пользователь мог заблокировать бота).');
        }

        return ApiResponse::ok(['sent' => count($sent), 'failed' => count($failed)]);
    }

    /** @return array<string, mixed> */
    public static function registration(DeviceRegistration $r): array
    {
        $end = $r->eligible_at ?? now();

        return [
            'id' => $r->public_id,
            'team' => $r->team?->apple_team_id,
            'team_name' => $r->team?->name,
            'team_status' => $r->team?->status->value,
            'status' => $r->status->value,
            'reason' => $r->status_reason,
            'has_apple_slot' => $r->apple_device_id !== null,
            'attempts' => $r->attempts,
            'created_at' => $r->created_at?->toIso8601ZuluString(),
            'eligible_at' => $r->eligible_at?->toIso8601ZuluString(),
            'waited_hours' => $r->created_at ? round($r->created_at->diffInMinutes($end, true) / 60, 1) : null,
            'compensation_minutes' => $r->delay_compensation_minutes,
            'gift_at' => $r->delay_gift_at?->toIso8601ZuluString(),
        ];
    }
}
