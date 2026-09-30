<?php

namespace App\Services\TelegramStore\Bot;

use App\Jobs\TelegramStoreBroadcastJob;
use App\Models\TelegramStoreBroadcast;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\BalanceLedger;
use App\Services\TelegramStore\CustomerService;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\StoreSettings;
use App\Services\TelegramStore\TelegramApi;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin tools inside the bot, for the Telegram ids in TELEGRAM_STORE_ADMIN_IDS.
 * Multi-step inputs (a new price, a broadcast message) keep a short-lived state.
 */
class AdminHandler
{
    private const ORDER = '([0-9a-z]{26})';

    private const PERIODS = ['1' => 'сегодня', '7' => '7 дней', '30' => '30 дней', 'all' => 'всё время'];

    public function __construct(
        private readonly Messenger $messenger,
        private readonly CustomerScreens $screens,
        private readonly OrderService $orders,
        private readonly CustomerService $customers,
        private readonly StoreSettings $settings,
        private readonly BalanceLedger $ledger,
        private readonly TelegramApi $api,
    ) {}

    /**
     * Returns false when the message is not for the admin tools, so the
     * customer flow handles it.
     *
     * @param  array<string, mixed>  $message
     */
    public function message(Context $ctx, array $message): bool
    {
        $text = trim((string) ($message['text'] ?? ''));
        $command = strtolower(preg_replace('/@\w+$/', '', strtok($text, ' ') ?: ''));
        $argument = trim((string) substr($text, strlen((string) strtok($text, ' '))));

        if ($command === '/cancel') {
            $this->forgetState($ctx);
            $this->messenger->show($ctx, $this->panel('Действие отменено.'));

            return true;
        }
        if ($command === '/admin') {
            $this->showPanel($ctx);

            return true;
        }
        if ($command === '/paid' && preg_match('/^[0-9A-Z]{26}$/i', $argument)) {
            $this->confirm($ctx, strtolower($argument));

            return true;
        }
        if ($command === '/user' && $argument !== '') {
            $this->showCustomer($ctx, $argument);

            return true;
        }

        $state = Cache::get($this->stateKey($ctx));
        if (! $state || str_starts_with($text, '/')) {
            return false;
        }

        $this->handleInput($ctx, $state, $text, $message);

        return true;
    }

    public function callback(Context $ctx, string $data): void
    {
        $this->messenger->answer($ctx);
        $action = substr($data, 4);

        match (true) {
            $action === 'panel' => $this->showPanel($ctx),
            (bool) preg_match('/^stats:(1|7|30|all)$/', $action, $m) => $this->messenger->show($ctx, $this->stats($m[1])),
            $action === 'reviews' => $this->messenger->show($ctx, $this->reviews()),
            (bool) preg_match('/^ord:'.self::ORDER.'$/', $action, $m) => $this->showOrder($ctx, $m[1]),
            (bool) preg_match('/^ok:'.self::ORDER.'$/', $action, $m) => $this->confirm($ctx, $m[1]),
            (bool) preg_match('/^no:'.self::ORDER.'$/', $action, $m) => $this->reject($ctx, $m[1]),
            $action === 'prices' => $this->messenger->show($ctx, $this->prices()),
            (bool) preg_match('/^price:(\w+)$/', $action, $m) => $this->ask($ctx, ['action' => 'price', 'plan' => $m[1]]),
            $action === 'ref' => $this->ask($ctx, ['action' => 'referral']),
            $action === 'bc' => $this->ask($ctx, ['action' => 'broadcast']),
            (bool) preg_match('/^bcgo:(\d+)$/', $action, $m) => $this->startBroadcast($ctx, (int) $m[1]),
            $action === 'find' => $this->ask($ctx, ['action' => 'find']),
            (bool) preg_match('/^bal:(\d+)$/', $action, $m) => $this->ask($ctx, ['action' => 'balance', 'customer' => (int) $m[1]]),
            default => null,
        };
    }

    /** Sends every admin the order with confirm / reject buttons. */
    public function notifyReview(TelegramStoreOrder $order): void
    {
        $screen = $this->orderScreen($order->refresh(), "🔔 <b>Покупатель сообщил об оплате</b>\nПроверьте поступление у платёжного провайдера.\n\n");
        foreach (config('telegram_store.admin_ids', []) as $adminId) {
            $this->messenger->notify((int) $adminId, $screen);
        }
    }

    public function panel(?string $notice = null): Screen
    {
        $today = Format::dayStart();
        $paidToday = $this->paid($today, real: true);
        $reviews = TelegramStoreOrder::query()->where('status', TelegramStoreOrder::REVIEW)->count();
        $mode = config('telegram_store.mock_payments') ? '🧪 тестовый (mock)' : '🔗 ссылки + ручная проверка';

        $text = ($notice ? e($notice)."\n\n" : '')
            ."<b>🛠 Админ-панель</b>\n"
            ."Режим оплаты: {$mode}\n\n"
            .'<b>Сегодня:</b> '.(clone $paidToday)->count().' покупок · '.Format::rub((int) (clone $paidToday)->sum('amount_due_rub'))
            .' · '.TelegramStoreCustomer::query()->where('created_at', '>=', $today)->count()." новых\n"
            ."<b>На проверке:</b> {$reviews}\n\n"
            .'Команды: /paid ID · /user ID или @username · /cancel';

        return new Screen($text, [
            [Screen::button('📊 Статистика', 'adm:stats:7'), Screen::button("🧾 На проверке ({$reviews})", 'adm:reviews')],
            [Screen::button('💲 Цены', 'adm:prices'), Screen::button('👥 Реферал '.$this->settings->referralPercent().'%', 'adm:ref')],
            [Screen::button('📣 Рассылка', 'adm:bc'), Screen::button('🔎 Пользователь', 'adm:find')],
            [Screen::button('‹ Меню магазина', 'menu')],
        ]);
    }

    public function stats(string $period): Screen
    {
        $since = $period === 'all' ? null : Format::dayStart((int) $period - 1);
        $newUsers = TelegramStoreCustomer::query()->when($since, fn ($q) => $q->where('created_at', '>=', $since));
        $created = TelegramStoreOrder::query()->when($since, fn ($q) => $q->where('created_at', '>=', $since))->count();
        $real = $this->paid($since, real: true);
        $paidCount = (clone $real)->count();
        $mock = $this->paid($since)->where('payment_provider', 'mock')->count();
        $byPlan = (clone $real)->selectRaw('plan_key, count(*) as n')->groupBy('plan_key')->pluck('n', 'plan_key');
        $bonuses = (int) DB::table('telegram_store_balance_transactions')
            ->where('type', BalanceLedger::REFERRAL_BONUS)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->sum('amount_rub');

        $plans = [];
        foreach ($this->settings->plans() as $key => $plan) {
            $plans[] = Format::months($plan['months']).' — '.($byPlan[$key] ?? 0);
        }
        $conversion = $created > 0 ? round(($paidCount + $mock) / $created * 100) : 0;

        $lines = [
            '<b>📊 Статистика · '.self::PERIODS[$period].'</b>',
            '',
            '👤 Новых пользователей: '.(clone $newUsers)->count().' (по приглашениям: '.(clone $newUsers)->whereNotNull('referrer_id')->count().')',
            "🧾 Заказов создано: {$created}",
            "✅ Оплачено: {$paidCount} · конверсия {$conversion}%",
            '💰 Выручка: <b>'.Format::rub((int) (clone $real)->sum('amount_due_rub')).'</b> + балансом '.Format::rub((int) (clone $real)->sum('balance_used_rub')),
            '📦 '.implode(' · ', $plans),
            '💸 Реферальных бонусов: '.Format::rub($bonuses),
        ];
        if ($mock > 0) {
            $lines[] = "🧪 Тестовых оплат: {$mock} (не входят в выручку)";
        }
        $lines[] = '';
        $lines[] = 'Всего пользователей: '.TelegramStoreCustomer::query()->count()
            .' · заблокировали бота: '.TelegramStoreCustomer::query()->whereNotNull('blocked_at')->count();
        $lines[] = 'Балансы пользователей: '.Format::rub((int) TelegramStoreCustomer::query()->sum('balance_rub'));

        $periods = [];
        foreach (self::PERIODS as $key => $label) {
            $periods[] = Screen::button(($key === $period ? '• ' : '').$label, 'adm:stats:'.$key);
        }

        return new Screen(implode("\n", $lines), [$periods, [Screen::button('‹ Панель', 'adm:panel')]]);
    }

    private function reviews(): Screen
    {
        $orders = TelegramStoreOrder::query()->where('status', TelegramStoreOrder::REVIEW)->oldest('updated_at')->limit(15)->get();
        if ($orders->isEmpty()) {
            return new Screen("<b>🧾 На проверке</b>\n\nНет заказов, ожидающих проверки. 👌", [[Screen::button('‹ Панель', 'adm:panel')]]);
        }
        $rows = $orders->map(fn (TelegramStoreOrder $order) => [Screen::button(
            '#'.$order->shortReference().' · '.Format::rub($order->amount_due_rub).' · '.($order->username ? '@'.$order->username : $order->telegram_user_id),
            'adm:ord:'.$order->public_id,
        )])->all();
        $rows[] = [Screen::button('‹ Панель', 'adm:panel')];

        return new Screen("<b>🧾 На проверке</b>\n\nВыберите заказ, сверьте оплату у провайдера и подтвердите.", $rows);
    }

    private function showOrder(Context $ctx, string $reference): void
    {
        $order = $this->orders->find($reference);
        $order ? $this->messenger->show($ctx, $this->orderScreen($order)) : $this->messenger->answer($ctx, 'Заказ не найден.', true);
    }

    private function orderScreen(TelegramStoreOrder $order, string $prefix = ''): Screen
    {
        $customer = $order->customer;
        $lines = [
            $prefix."<b>Заказ</b> <code>{$order->reference()}</code>",
            'Покупатель: '.e($customer?->displayName() ?? ($order->username ? '@'.$order->username : (string) $order->telegram_user_id))
                ." (<code>{$order->telegram_user_id}</code>)",
            'Тариф: '.$this->screens->planName($order).' · '.Format::rub($order->price_rub),
            '<b>К оплате деньгами: '.Format::rub($order->amount_due_rub).'</b>',
        ];
        if ($order->balance_used_rub > 0) {
            $lines[] = 'Оплачено балансом: '.Format::rub($order->balance_used_rub);
        }
        $lines[] = 'Способ: '.e($order->payment_method ?? '—');
        $lines[] = 'Создан: '.Format::date($order->created_at).' '.Format::time($order->created_at);
        $lines[] = 'Статус: '.Format::status($order->status);

        $rows = [];
        if (in_array($order->status, [TelegramStoreOrder::PENDING, TelegramStoreOrder::REVIEW], true)) {
            $rows[] = [Screen::button('✅ Подтвердить оплату', 'adm:ok:'.$order->public_id), Screen::button('❌ Отклонить', 'adm:no:'.$order->public_id)];
        }
        $rows[] = [Screen::button('‹ На проверке', 'adm:reviews')];

        return new Screen(implode("\n", $lines), $rows);
    }

    private function confirm(Context $ctx, string $reference): void
    {
        if (config('telegram_store.mock_payments')) {
            $this->messenger->show($ctx, new Screen('В тестовом режиме оплату подтверждает кнопка «Симулировать оплату» у покупателя.', [[Screen::button('‹ Панель', 'adm:panel')]]));

            return;
        }
        $order = $this->orders->find($reference);
        $completed = $order ? $this->orders->complete($order, $order->payment_provider ?? 'manual', $ctx->userId()) : null;
        if (! $completed) {
            $this->messenger->show($ctx, new Screen('Заказ не найден или уже обработан.', [[Screen::button('‹ На проверке', 'adm:reviews')]]));

            return;
        }
        $this->messenger->orderCompleted($completed);
        $this->messenger->show($ctx, new Screen(
            "✅ Оплата заказа <code>{$order->reference()}</code> подтверждена, код отправлен покупателю."
            .($completed->referralBonus > 0 ? "\nПригласившему начислено ".Format::rub($completed->referralBonus).'.' : ''),
            [[Screen::button('🧾 На проверке', 'adm:reviews'), Screen::button('‹ Панель', 'adm:panel')]],
        ));
    }

    private function reject(Context $ctx, string $reference): void
    {
        $order = $this->orders->find($reference);
        if (! $order || ! $this->orders->close($order, TelegramStoreOrder::REJECTED)) {
            $this->messenger->show($ctx, new Screen('Заказ не найден или уже обработан.', [[Screen::button('‹ На проверке', 'adm:reviews')]]));

            return;
        }
        $order->update(['reviewed_by' => $ctx->userId()]);
        $this->messenger->orderRejected($order);
        $this->messenger->show($ctx, new Screen(
            "❌ Заказ <code>{$order->reference()}</code> отклонён, покупатель уведомлён.",
            [[Screen::button('🧾 На проверке', 'adm:reviews'), Screen::button('‹ Панель', 'adm:panel')]],
        ));
    }

    private function prices(): Screen
    {
        $rows = [];
        foreach ($this->settings->plans() as $key => $plan) {
            $rows[] = [Screen::button('✏️ '.Format::months($plan['months']).' — '.Format::rub($plan['price']), 'adm:price:'.$key)];
        }
        $rows[] = [Screen::button('‹ Панель', 'adm:panel')];

        return new Screen("<b>💲 Цены</b>\n\nНажмите на тариф, чтобы изменить цену. Новая цена действует для новых заказов.", $rows);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function ask(Context $ctx, array $state): void
    {
        $prompt = match ($state['action']) {
            'price' => ($plan = $this->settings->plan($state['plan']))
                ? 'Отправьте новую цену для тарифа «'.Format::months($plan['months']).'» в рублях (сейчас '.Format::rub($plan['price']).').'
                : null,
            'referral' => 'Отправьте процент реферального бонуса от 0 до 50 (сейчас '.$this->settings->referralPercent().'%).',
            'broadcast' => "Отправьте сообщение для рассылки: текст, фото, видео — как есть.\nПеред отправкой покажу предпросмотр.",
            'find' => 'Отправьте Telegram ID или @username пользователя.',
            'balance' => "Отправьте сумму изменения баланса, например <code>200</code> или <code>-150</code>.\nПри начислении пользователь получит уведомление.",
            default => null,
        };
        if ($prompt === null) {
            return;
        }
        Cache::put($this->stateKey($ctx), $state, now()->addMinutes(15));
        $this->messenger->show($ctx, new Screen($prompt."\n\n/cancel — отмена", [[Screen::button('‹ Отмена', 'adm:panel')]]));
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $message
     */
    private function handleInput(Context $ctx, array $state, string $text, array $message): void
    {
        $number = preg_match('/^[+-]?\d{1,7}$/', str_replace([' ', '₽'], '', $text)) ? (int) str_replace([' ', '₽'], '', $text) : null;
        $retry = fn (string $error) => $this->messenger->show($ctx, new Screen(e($error)."\n\n/cancel — отмена"));

        switch ($state['action']) {
            case 'price':
                if ($number === null || $number < 1 || $number > 1_000_000) {
                    $retry('Нужна цена целым числом в рублях, например 590.');

                    return;
                }
                $this->settings->setPrice($state['plan'], $number);
                $this->forgetState($ctx);
                $this->messenger->show($ctx, $this->prices());

                return;

            case 'referral':
                if ($number === null || $number < 0 || $number > 50) {
                    $retry('Нужно число от 0 до 50.');

                    return;
                }
                $this->settings->setReferralPercent($number);
                $this->forgetState($ctx);
                $this->messenger->show($ctx, $this->panel("Реферальный бонус: {$number}%."));

                return;

            case 'find':
                $this->forgetState($ctx);
                $this->showCustomer($ctx, $text);

                return;

            case 'balance':
                $customer = TelegramStoreCustomer::query()->find($state['customer']);
                if (! $customer || $number === null || $number === 0) {
                    $retry('Нужна сумма целым числом, например 200 или -150.');

                    return;
                }
                try {
                    $balance = $this->ledger->change($customer->id, $number, BalanceLedger::ADMIN_ADJUSTMENT, note: 'by '.$ctx->userId());
                } catch (InvalidArgumentException) {
                    $retry('Нельзя списать больше, чем на балансе ('.Format::rub($customer->balance_rub).').');

                    return;
                }
                $this->forgetState($ctx);
                if ($number > 0) {
                    $this->messenger->notify($customer->chat_id, $this->screens->balanceGift($number, $balance));
                }
                $this->messenger->show($ctx, $this->customerScreen($customer->refresh()));

                return;

            case 'broadcast':
                $this->forgetState($ctx);
                $messageId = (int) $message['message_id'];
                $this->api->copyMessage($ctx->chatId, $ctx->chatId, $messageId);
                $recipients = TelegramStoreCustomer::query()->whereNull('blocked_at')->count();
                $this->messenger->show($ctx, new Screen(
                    "☝️ Так увидят сообщение пользователи.\nОтправить его <b>{$recipients}</b> получателям?",
                    [[Screen::button('✅ Отправить', 'adm:bcgo:'.$messageId), Screen::button('❌ Отмена', 'adm:panel')]],
                ));

                return;
        }
    }

    private function startBroadcast(Context $ctx, int $messageId): void
    {
        // A double tap must not send the same message twice.
        if (! Cache::add("telegram_store.broadcast.{$ctx->chatId}.{$messageId}", true, now()->addDay())) {
            $this->messenger->answer($ctx, 'Эта рассылка уже запущена.', true);

            return;
        }
        $broadcast = TelegramStoreBroadcast::create([
            'admin_chat_id' => $ctx->chatId,
            'from_chat_id' => $ctx->chatId,
            'message_id' => $messageId,
            'total' => TelegramStoreCustomer::query()->whereNull('blocked_at')->count(),
        ]);
        TelegramStoreBroadcastJob::dispatch($broadcast->id);
        $this->messenger->show($ctx, new Screen(
            "📣 Рассылка запущена: {$broadcast->total} получателей.\nКогда закончу, пришлю отчёт.",
            [[Screen::button('‹ Панель', 'adm:panel')]],
        ));
    }

    private function showCustomer(Context $ctx, string $query): void
    {
        $customer = $this->customers->find($query);
        $this->messenger->show($ctx, $customer
            ? $this->customerScreen($customer)
            : new Screen('Пользователь не найден. Он должен хотя бы раз открыть бота.', [[Screen::button('🔎 Искать снова', 'adm:find'), Screen::button('‹ Панель', 'adm:panel')]]));
    }

    private function customerScreen(TelegramStoreCustomer $customer): Screen
    {
        $paid = $customer->orders()->where('status', TelegramStoreOrder::PAID);
        $lines = [
            '<b>👤 '.e($customer->displayName()).'</b>',
            "Telegram ID: <code>{$customer->telegram_user_id}</code>",
            'С нами с: '.Format::date($customer->created_at),
            '💰 Баланс: <b>'.Format::rub($customer->balance_rub).'</b>',
            '🛍 Покупок: '.(clone $paid)->count().' на '.Format::rub((int) (clone $paid)->sum('amount_due_rub')),
            '👥 Пригласил: '.$customer->referrals()->count().' · заработал '.Format::rub($customer->referral_earned_rub),
            'Пришёл от: '.($customer->referrer ? e($customer->referrer->displayName()) : '—'),
            'Статус: '.($customer->blocked_at ? '🚫 заблокировал бота' : '✅ активен'),
        ];
        $recent = $customer->orders()->latest('id')->limit(5)->get();
        if ($recent->isNotEmpty()) {
            $lines[] = '';
            foreach ($recent as $order) {
                $lines[] = "<code>{$order->reference()}</code> · ".Format::rub($order->price_rub).' · '.Format::status($order->status);
            }
        }

        return new Screen(implode("\n", $lines), [
            [Screen::button('✏️ Изменить баланс', 'adm:bal:'.$customer->id)],
            [Screen::button('🔎 Другой пользователь', 'adm:find'), Screen::button('‹ Панель', 'adm:panel')],
        ]);
    }

    /**
     * Paid orders since a moment; with $real, simulated (mock) payments are left out.
     *
     * @return Builder<TelegramStoreOrder>
     */
    private function paid(?CarbonInterface $since, bool $real = false): Builder
    {
        return TelegramStoreOrder::query()
            ->where('status', TelegramStoreOrder::PAID)
            ->when($since, fn ($q) => $q->where('paid_at', '>=', $since))
            ->when($real, fn ($q) => $q->where(fn ($q) => $q->whereNull('payment_provider')->orWhere('payment_provider', '!=', 'mock')));
    }

    private function stateKey(Context $ctx): string
    {
        return 'telegram_store.admin_state.'.$ctx->userId();
    }

    private function forgetState(Context $ctx): void
    {
        Cache::forget($this->stateKey($ctx));
    }

    private function showPanel(Context $ctx): void
    {
        $this->forgetState($ctx);
        $this->messenger->show($ctx, $this->panel());
    }
}
