<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Jobs\TelegramStoreBroadcastJob;
use App\Models\TelegramStoreBroadcast;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\TelegramStorePromoCode;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\TelegramStore\BalanceLedger;
use App\Services\TelegramStore\Bot\CustomerScreens;
use App\Services\TelegramStore\Bot\Messenger;
use App\Services\TelegramStore\OrderFulfillment;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\Payments\GatewayResolver;
use App\Services\TelegramStore\PromoService;
use App\Services\TelegramStore\StoreSettings;
use App\Services\TelegramStore\TelegramApi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

/**
 * The Telegram store from the web panel: what the bot's /admin offers (orders to confirm,
 * promo codes, customer balances, prices, broadcasts), so nobody needs the bot for it.
 */
class StoreController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /* ---------- Orders ---------- */

    public function orders(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([TelegramStoreOrder::PENDING, TelegramStoreOrder::REVIEW, TelegramStoreOrder::PAID,
                TelegramStoreOrder::CANCELLED, TelegramStoreOrder::EXPIRED, TelegramStoreOrder::REJECTED])],
            'channel' => ['nullable', 'in:bot,web'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TelegramStoreOrder::query()->with(['promoCode', 'user', 'renewUser'])->latest('id');
        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
        if (($filters['channel'] ?? null) === 'web') {
            $query->whereNotNull('user_id')->whereNull('chat_id');
        } elseif (($filters['channel'] ?? null) === 'bot') {
            $query->whereNotNull('chat_id');
        }
        if (filled($filters['q'] ?? null)) {
            $q = trim($filters['q']);
            $query->where(function (Builder $where) use ($q) {
                $plain = ltrim($q, '@');
                $where->where('public_id', 'like', '%'.strtolower(addcslashes($plain, '%_\\')))
                    ->orWhere('username', $plain)
                    ->orWhere('payment_reference', $q)
                    ->orWhereHas('user', fn (Builder $user) => $user->where('email', 'like', '%'.addcslashes($q, '%_\\').'%'));
                if (ctype_digit($plain)) {
                    $where->orWhere('telegram_user_id', (int) $plain);
                }
            });
        }

        $page = $query->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($page, array_map(fn (TelegramStoreOrder $order) => self::order($order), $page->items()));
    }

    /** Confirms a manual payment: same as «Подтвердить» in the bot. */
    public function confirmOrder(Request $request, string $reference, OrderService $orders, OrderFulfillment $fulfillment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        if (GatewayResolver::mock()) {
            throw new ApiException(ErrorCode::Conflict, 'Магазин в тестовом режиме (mock): оплату подтверждает покупатель.');
        }
        $order = $orders->find($reference) ?? throw new ApiException(ErrorCode::NotFound, 'Заказ не найден.');
        $admin = (int) (config('telegram_store.admin_ids')[0] ?? 0);
        $completed = $orders->complete($order, $order->payment_provider ?? 'manual', $admin);
        if ($completed === null) {
            throw new ApiException(ErrorCode::Conflict, 'Заказ уже обработан или не ждёт оплаты.');
        }
        $fulfillment->deliver($completed);
        $this->audit->record('store.order_confirmed', null, after: ['order' => $order->reference(), 'amount_rub' => $order->amount_due_rub], reason: $data['reason']);

        return ApiResponse::ok(self::order($order->refresh()));
    }

    public function rejectOrder(Request $request, string $reference, OrderService $orders, Messenger $messenger): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $order = $orders->find($reference) ?? throw new ApiException(ErrorCode::NotFound, 'Заказ не найден.');
        if (! $orders->close($order, TelegramStoreOrder::REJECTED)) {
            throw new ApiException(ErrorCode::Conflict, 'Заказ уже обработан.');
        }
        try {
            $messenger->orderRejected($order);
        } catch (Throwable $exception) {
            report($exception);
        }
        $this->audit->record('store.order_rejected', null, after: ['order' => $order->reference()], reason: $data['reason']);

        return ApiResponse::ok(self::order($order->refresh()));
    }

    /* ---------- Promo codes ---------- */

    public function promoCodes(PromoService $promos): JsonResponse
    {
        return ApiResponse::ok(TelegramStorePromoCode::query()->latest('id')->limit(200)->get()
            ->map(fn (TelegramStorePromoCode $promo) => self::promo($promo, $promos))->all());
    }

    public function storePromoCode(Request $request, PromoService $promos, StoreSettings $settings): JsonResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_-]{3,32}$/', Rule::unique('telegram_store_promo_codes', 'code')],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'integer', 'min:1', $request->input('type') === 'percent' ? 'max:100' : 'max:1000000'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date', 'after:now', 'before:2037-12-31'],
            'plan_keys' => ['nullable', 'array'],
            'plan_keys.*' => [Rule::in(array_keys($settings->plans()))],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $promo = $promos->create(array_filter([
            'code' => $data['code'],
            'type' => $data['type'],
            'value' => (int) $data['value'],
            'max_uses' => $data['max_uses'] ?? null,
            'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->utc() : null,
            'plan_keys' => ! empty($data['plan_keys']) ? array_values(array_unique($data['plan_keys'])) : null,
            'note' => $data['note'] ?? null,
        ], fn ($value) => $value !== null), (int) (config('telegram_store.admin_ids')[0] ?? 0));
        $this->audit->record('store.promo_created', null, after: ['code' => $promo->code, 'label' => $promo->label()]);

        return ApiResponse::ok(self::promo($promo, $promos), 201);
    }

    public function togglePromoCode(int $promo, PromoService $promos): JsonResponse
    {
        $record = TelegramStorePromoCode::query()->findOrFail($promo);
        $record->update(['active' => ! $record->active]);
        $this->audit->record('store.promo_toggled', null, after: ['code' => $record->code, 'active' => $record->active]);

        return ApiResponse::ok(self::promo($record, $promos));
    }

    /* ---------- Bot customers ---------- */

    public function customers(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'with_balance' => ['nullable', 'boolean'],
        ]);
        $query = TelegramStoreCustomer::query()->with('referrer')->withCount(['orders as paid_orders' => fn ($q) => $q->where('status', TelegramStoreOrder::PAID)])
            ->latest('id');
        if (filled($filters['q'] ?? null)) {
            $q = ltrim(trim($filters['q']), '@');
            $query->where(fn (Builder $where) => ctype_digit($q)
                ? $where->where('telegram_user_id', (int) $q)->orWhere('id', (int) $q)
                : $where->where('username', 'like', addcslashes($q, '%_\\').'%')->orWhere('first_name', 'like', '%'.addcslashes($q, '%_\\').'%'));
        }
        if (! empty($filters['with_balance'])) {
            $query->where('balance_rub', '>', 0);
        }
        $page = $query->paginate(25);

        return ApiResponse::paginated($page, array_map(fn (TelegramStoreCustomer $customer) => [
            'id' => $customer->id,
            'telegram_user_id' => (int) $customer->telegram_user_id,
            'username' => $customer->username,
            'first_name' => $customer->first_name,
            'balance_rub' => (int) $customer->balance_rub,
            'referral_earned_rub' => (int) $customer->referral_earned_rub,
            'referrer' => $customer->referrer?->displayName(),
            'paid_orders' => (int) $customer->paid_orders,
            'blocked_bot' => $customer->blocked_at !== null,
            'last_seen_at' => $customer->last_seen_at?->toIso8601ZuluString(),
            'created_at' => $customer->created_at?->toIso8601ZuluString(),
        ], $page->items()));
    }

    public function adjustBalance(Request $request, int $customer, BalanceLedger $ledger, Messenger $messenger, CustomerScreens $screens): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'between:-1000000,1000000', 'not_in:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $record = TelegramStoreCustomer::query()->findOrFail($customer);
        try {
            $balance = $ledger->change($record->id, (int) $data['amount'], BalanceLedger::ADMIN_ADJUSTMENT, note: 'web: '.mb_substr($data['reason'], 0, 200));
        } catch (InvalidArgumentException) {
            throw new ApiException(ErrorCode::Conflict, "Нельзя списать больше, чем на балансе ({$record->balance_rub} ₽).");
        }
        if ($data['amount'] > 0) {
            try {
                $messenger->notify($record->chat_id, $screens->balanceGift((int) $data['amount'], $balance));
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $this->audit->record('store.balance_adjusted', null, after: ['customer' => $record->telegram_user_id, 'amount_rub' => (int) $data['amount'], 'balance_rub' => $balance], reason: $data['reason']);

        return ApiResponse::ok(['id' => $record->id, 'balance_rub' => $balance]);
    }

    /* ---------- Prices and referral share ---------- */

    public function settings(StoreSettings $settings): JsonResponse
    {
        return ApiResponse::ok([
            'plans' => collect($settings->plans())->map(fn (array $plan, string $key) => $plan + ['key' => $key, 'saving_percent' => $settings->savingPercent($key)])->values()->all(),
            'referral_percent' => $settings->referralPercent(),
            'mock_payments' => GatewayResolver::mock(),
        ]);
    }

    public function updateSettings(Request $request, StoreSettings $settings): JsonResponse
    {
        $data = $request->validate([
            'prices' => ['nullable', 'array'],
            'prices.*' => ['integer', 'min:1', 'max:1000000'],
            'referral_percent' => ['nullable', 'integer', 'min:0', 'max:90'],
        ]);
        $before = ['prices' => collect($settings->plans())->map(fn ($plan) => $plan['price'])->all(), 'referral_percent' => $settings->referralPercent()];
        foreach ($data['prices'] ?? [] as $key => $price) {
            if ($settings->plan((string) $key) === null) {
                throw new ApiException(ErrorCode::ValidationFailed, "Неизвестный тариф {$key}.");
            }
            $settings->setPrice((string) $key, (int) $price);
        }
        if (array_key_exists('referral_percent', $data) && $data['referral_percent'] !== null) {
            $settings->setReferralPercent((int) $data['referral_percent']);
        }
        $settings->flush();
        $this->audit->record('store.settings_changed', null, before: $before, after: [
            'prices' => collect($settings->plans())->map(fn ($plan) => $plan['price'])->all(), 'referral_percent' => $settings->referralPercent(),
        ]);

        return $this->settings($settings);
    }

    /* ---------- Broadcasts ---------- */

    public function broadcasts(): JsonResponse
    {
        return ApiResponse::ok([
            'recipients' => TelegramStoreCustomer::query()->whereNull('blocked_at')->count(),
            'items' => TelegramStoreBroadcast::query()->latest('id')->limit(20)->get()->map(fn (TelegramStoreBroadcast $b) => [
                'id' => $b->id, 'status' => $b->status, 'total' => $b->total, 'sent' => $b->sent, 'failed' => $b->failed,
                'created_at' => $b->created_at?->toIso8601ZuluString(), 'finished_at' => $b->finished_at?->toIso8601ZuluString(),
            ])->all(),
        ]);
    }

    /**
     * Sends the text to the first store admin's chat first (the copy Telegram then forwards to
     * everyone), then starts the same broadcast job the bot uses.
     */
    public function storeBroadcast(Request $request, TelegramApi $api): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:3500']]);
        $adminChat = (int) (config('telegram_store.admin_ids')[0] ?? 0);
        if ($adminChat === 0) {
            throw new ApiException(ErrorCode::Conflict, 'Не настроены администраторы бота (TELEGRAM_STORE_ADMIN_IDS).');
        }
        try {
            $message = $api->sendMessage($adminChat, e($data['text']));
        } catch (Throwable $exception) {
            report($exception);
            throw new ApiException(ErrorCode::Conflict, 'Telegram не принял сообщение: '.$exception->getMessage());
        }
        $broadcast = TelegramStoreBroadcast::create([
            'admin_chat_id' => $adminChat,
            'from_chat_id' => $adminChat,
            'message_id' => (int) ($message['message_id'] ?? 0),
            'total' => TelegramStoreCustomer::query()->whereNull('blocked_at')->count(),
        ]);
        TelegramStoreBroadcastJob::dispatch($broadcast->id);
        $this->audit->record('store.broadcast_started', null, after: ['broadcast' => $broadcast->id, 'total' => $broadcast->total], reason: mb_substr($data['text'], 0, 500));

        return ApiResponse::ok(['id' => $broadcast->id, 'total' => $broadcast->total], 202);
    }

    /* ---------- Presenters ---------- */

    /** @return array<string, mixed> */
    public static function order(TelegramStoreOrder $order): array
    {
        $account = $order->user ?? $order->renewUser;

        return [
            'reference' => $order->reference(),
            'short' => $order->shortReference(),
            'status' => $order->status,
            'channel' => $order->isWeb() ? 'web' : 'bot',
            'renewal' => $order->isRenewal(),
            'plan_key' => $order->plan_key,
            'duration_days' => $order->duration_days,
            'price_rub' => (int) $order->price_rub,
            'discount_rub' => (int) $order->discount_rub,
            'balance_used_rub' => (int) $order->balance_used_rub,
            'amount_due_rub' => (int) $order->amount_due_rub,
            'promo' => $order->promoCode?->code,
            'payment_method' => $order->payment_method,
            'payment_provider' => $order->payment_provider,
            'payment_reference' => $order->payment_reference,
            'telegram' => $order->telegram_user_id ? [
                'id' => (int) $order->telegram_user_id, 'username' => $order->username, 'first_name' => $order->first_name,
            ] : null,
            'account' => $account instanceof User ? ['id' => $account->public_id, 'number' => $account->id, 'email' => $account->email] : null,
            'created_at' => $order->created_at?->toIso8601ZuluString(),
            'paid_at' => $order->paid_at?->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    private static function promo(TelegramStorePromoCode $promo, PromoService $promos): array
    {
        return [
            'id' => $promo->id,
            'code' => $promo->code,
            'type' => $promo->type,
            'value' => $promo->value,
            'label' => $promo->label(),
            'max_uses' => $promo->max_uses,
            'plan_keys' => $promo->plan_keys,
            'expires_at' => $promo->expires_at?->toIso8601ZuluString(),
            'active' => (bool) ($promo->active ?? true),
            'note' => $promo->note,
            'usage' => $promos->usage($promo),
            'created_at' => $promo->created_at?->toIso8601ZuluString(),
        ];
    }
}
