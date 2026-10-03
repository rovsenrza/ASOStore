<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\CompletedOrder;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\Payments\GatewayResolver;
use App\Services\TelegramStore\Payments\MockGateway;
use App\Services\TelegramStore\PromoService;
use Illuminate\Support\Facades\Cache;

class CustomerHandler
{
    private const ORDER = '([0-9a-z]{26})';

    public function __construct(
        private readonly CustomerScreens $screens,
        private readonly Messenger $messenger,
        private readonly OrderService $orders,
        private readonly GatewayResolver $gateways,
        private readonly AdminHandler $admin,
        private readonly PromoService $promos,
        private readonly ChannelGate $gate,
    ) {}

    public function message(Context $ctx, string $text): void
    {
        $command = strtolower(preg_replace('/@\w+$/', '', strtok($text, ' ') ?: ''));
        $lower = mb_strtolower($text);

        // A promo code typed after pressing «Ввести промокод».
        $awaiting = Cache::get($this->promoStateKey($ctx));
        if ($awaiting && $text !== '' && ! str_starts_with($text, '/')) {
            $this->enterPromo($ctx, (string) $awaiting, $text);

            return;
        }
        Cache::forget($this->promoStateKey($ctx));

        if ($command === '/start' && preg_match('/^\/start\s+promo_([A-Za-z0-9_-]{3,32})$/', $text, $match)) {
            $this->rememberPromo($ctx, $match[1]);

            return;
        }

        // «Оплатить в Telegram» on the website's purchase page: straight to the order for that plan.
        if ($command === '/start' && preg_match('/^\/start\s+buy(?:_(\w{1,32}))?$/', $text, $match)) {
            if ($this->held($ctx, isset($match[1]) ? 'plan:'.$match[1] : 'buy')) {
                return;
            }
            isset($match[1]) ? $this->startOrder($ctx, $match[1]) : $this->messenger->show($ctx, $this->screens->plans($ctx->customer, $this->promos->remembered($ctx->userId())));

            return;
        }

        if (($command === '/buy' || str_contains($lower, 'купить')) && $this->held($ctx, 'buy')) {
            return;
        }

        match (true) {
            in_array($command, ['/start', '/menu'], true), $lower === 'меню' => $this->messenger->welcome($ctx->chatId),
            $command === '/buy', str_contains($lower, 'купить') => $this->messenger->show($ctx, $this->screens->plans($ctx->customer)),
            $command === '/profile', str_contains($lower, 'профиль') => $this->messenger->show($ctx, $this->screens->profile($ctx->customer)),
            $command === '/invite', str_contains($lower, 'пригласить'), str_contains($lower, 'реферал') => $this->messenger->show($ctx, $this->screens->invite($ctx->customer)),
            $command === '/help', str_contains($lower, 'инструкция'), str_contains($lower, 'поддержк') => $this->messenger->show($ctx, $this->screens->help()),
            default => $this->messenger->show($ctx, new Screen('Выберите раздел в меню 👇', $this->screens->menuRows())),
        };
    }

    public function callback(Context $ctx, string $data): void
    {
        $customer = $ctx->customer;

        // «Я подписался» on the channel screen: check again, then carry on where they were going.
        if (preg_match('/^sub:(.{1,56})$/', $data, $match)) {
            if (! $this->gate->passes($ctx->userId())) {
                $this->messenger->answer($ctx, 'Подписка пока не видна. Подпишитесь на канал и нажмите кнопку ещё раз.', true);

                return;
            }
            $this->messenger->answer($ctx, 'Спасибо за подписку! ✅');
            $this->callback($ctx, $match[1]);

            return;
        }

        // Buying and paying need a subscription to the news channel.
        if (preg_match('/^(buy|plan:\w+|(order|bal|pay:\w+|paid|mockpay|promo):[0-9a-z]{26})$/', $data) === 1 && $this->held($ctx, $data)) {
            return;
        }

        if (in_array($data, ['menu', 'buy', 'profile', 'orders', 'invite', 'help'], true)) {
            $this->messenger->answer($ctx);
            $this->messenger->show($ctx, match ($data) {
                'menu' => $this->screens->menu(),
                'buy' => $this->screens->plans($customer, $this->promos->remembered($customer->telegram_user_id)),
                'profile' => $this->screens->profile($customer),
                'orders' => $this->screens->orders($customer),
                'invite' => $this->screens->invite($customer),
                'help' => $this->screens->help(),
            });

            return;
        }

        if (preg_match('/^plan:(\w+)$/', $data, $match)) {
            if ($this->planMissing($match[1])) {
                $this->messenger->answer($ctx, 'Этот тариф больше недоступен.', true);
                $this->messenger->show($ctx, $this->screens->plans($customer));

                return;
            }
            $this->messenger->answer($ctx);
            $this->startOrder($ctx, $match[1]);

            return;
        }

        if (! preg_match('/^(order|bal|paid|mockpay|cancel|code|promo|unpromo|pay:(\w+)):'.self::ORDER.'$/', $data, $match)) {
            $this->messenger->answer($ctx);

            return;
        }
        $action = str_starts_with($match[1], 'pay:') ? 'pay' : $match[1];
        $order = $this->orders->find($match[3], $ctx->userId());
        if (! $order) {
            $this->messenger->answer($ctx, 'Заказ не найден.', true);

            return;
        }

        match ($action) {
            'order' => $this->showOrder($ctx, $order),
            'bal' => $this->payWithBalance($ctx, $order),
            'pay' => $this->startCheckout($ctx, $order, $match[2]),
            'paid' => $this->reportPaid($ctx, $order),
            'mockpay' => $this->simulatePayment($ctx, $order),
            'cancel' => $this->cancel($ctx, $order),
            'code' => $this->showCode($ctx, $order),
            'promo' => $this->askPromo($ctx, $order),
            'unpromo' => $this->removePromo($ctx, $order),
            default => $this->messenger->answer($ctx),
        };
    }

    /** Opens an order for the plan (a remembered promo code applies) and shows how to pay it. */
    private function startOrder(Context $ctx, string $planKey): void
    {
        if ($this->planMissing($planKey)) {
            $this->messenger->show($ctx, $this->screens->plans($ctx->customer, $this->promos->remembered($ctx->userId())));

            return;
        }
        $order = $this->orders->create($ctx->customer, $planKey);
        if ($completed = $this->promos->applyRemembered($order)) {
            $this->finish($ctx, $completed);

            return;
        }
        $this->messenger->show($ctx, $this->screens->order($order, $ctx->customer->refresh()));
    }

    private function askPromo(Context $ctx, TelegramStoreOrder $order): void
    {
        if (! $order->isPayable()) {
            $this->showOrder($ctx, $order);

            return;
        }
        $this->messenger->answer($ctx);
        Cache::put($this->promoStateKey($ctx), $order->public_id, now()->addMinutes(15));
        $this->messenger->show($ctx, $this->screens->promoPrompt($order));
    }

    private function enterPromo(Context $ctx, string $reference, string $code): void
    {
        $order = $this->orders->find($reference, $ctx->userId());
        if (! $order || ! $order->isPayable()) {
            Cache::forget($this->promoStateKey($ctx));
            $this->messenger->show($ctx, $this->screens->plans($ctx->customer));

            return;
        }
        $result = $this->promos->apply($order, $code);
        if (is_string($result)) {
            $this->messenger->show($ctx, $this->screens->promoPrompt($order, $result));

            return;
        }
        Cache::forget($this->promoStateKey($ctx));
        $result instanceof CompletedOrder
            ? $this->finish($ctx, $result)
            : $this->messenger->show($ctx, $this->screens->order($order, $ctx->customer->refresh()));
    }

    private function removePromo(Context $ctx, TelegramStoreOrder $order): void
    {
        $this->promos->remove($order);
        $this->messenger->answer($ctx, 'Промокод убран');
        $this->messenger->show($ctx, $this->screens->order($order, $ctx->customer->refresh()));
    }

    private function rememberPromo(Context $ctx, string $code): void
    {
        $promo = $this->promos->find($code);
        if (! $promo || ! $promo->active || ($promo->expires_at && $promo->expires_at->isPast())) {
            $this->messenger->show($ctx, new Screen('Промокод по ссылке недействителен, но вы можете выбрать тариф 👇', $this->screens->menuRows()));

            return;
        }
        $this->promos->remember($ctx->userId(), $promo);
        $this->messenger->show($ctx, $this->screens->plans($ctx->customer, $promo));
    }

    private function finish(Context $ctx, CompletedOrder $completed): void
    {
        $this->messenger->show($ctx, $this->screens->paidPlaceholder($completed->order));
        $this->messenger->orderCompleted($completed);
    }

    private function promoStateKey(Context $ctx): string
    {
        return 'telegram_store.customer_promo_state.'.$ctx->userId();
    }

    private function showOrder(Context $ctx, TelegramStoreOrder $order): void
    {
        if (! $order->isPayable()) {
            $this->messenger->answer($ctx, 'Заказ #'.$order->shortReference().': '.Format::status($order->status), true);
            $this->messenger->show($ctx, $this->screens->plans($ctx->customer));

            return;
        }
        $this->messenger->answer($ctx);
        $this->messenger->show($ctx, $this->screens->order($order, $ctx->customer));
    }

    private function payWithBalance(Context $ctx, TelegramStoreOrder $order): void
    {
        if (! $order->isPayable()) {
            $this->showOrder($ctx, $order);

            return;
        }
        $completed = $this->orders->applyBalance($order);
        if ($completed) {
            $this->messenger->answer($ctx, 'Оплачено балансом ✅');
            $this->messenger->show($ctx, $this->screens->paidPlaceholder($order));
            $this->messenger->orderCompleted($completed);

            return;
        }
        $this->messenger->answer($ctx, 'Баланс списан, осталось доплатить '.Format::rub($order->amount_due_rub));
        $this->messenger->show($ctx, $this->screens->order($order, $ctx->customer->refresh()));
    }

    private function startCheckout(Context $ctx, TelegramStoreOrder $order, string $method): void
    {
        $gateway = $this->gateways->current();
        if (! $order->isPayable() || ! array_key_exists($method, $gateway->methods($ctx->userId()))) {
            $this->showOrder($ctx, $order);

            return;
        }
        $this->messenger->answer($ctx);
        $this->orders->selectMethod($order, $method, $gateway->name());
        $this->messenger->show($ctx, $this->screens->checkout($order, $gateway->checkout($order, $method)));
    }

    private function reportPaid(Context $ctx, TelegramStoreOrder $order): void
    {
        if ($order->status === TelegramStoreOrder::REVIEW) {
            $this->messenger->answer($ctx, 'Уже проверяем — код придёт сюда.');

            return;
        }
        if (config('telegram_store.mock_payments') || ! $this->orders->markForReview($order)) {
            $this->showOrder($ctx, $order);

            return;
        }
        $this->messenger->answer($ctx);
        $this->messenger->show($ctx, $this->screens->underReview($order->refresh()));
        $this->admin->notifyReview($order);
    }

    private function simulatePayment(Context $ctx, TelegramStoreOrder $order): void
    {
        if (! config('telegram_store.mock_payments') || ! MockGateway::isTester($ctx->userId())) {
            $this->messenger->answer($ctx, 'Тестовая оплата недоступна.', true);

            return;
        }
        $completed = $this->orders->complete($order, 'mock');
        if (! $completed) {
            $this->showOrder($ctx, $order);

            return;
        }
        $this->messenger->answer($ctx, 'Тестовая оплата прошла ✅');
        $this->messenger->show($ctx, $this->screens->paidPlaceholder($order));
        $this->messenger->orderCompleted($completed);
    }

    private function cancel(Context $ctx, TelegramStoreOrder $order): void
    {
        $this->orders->close($order, TelegramStoreOrder::CANCELLED);
        $this->messenger->answer($ctx, 'Заказ отменён');
        $this->messenger->show($ctx, $this->screens->plans($ctx->customer->refresh()));
    }

    private function showCode(Context $ctx, TelegramStoreOrder $order): void
    {
        if ($order->status !== TelegramStoreOrder::PAID) {
            $this->messenger->answer($ctx, 'Код появится после оплаты.', true);

            return;
        }
        $this->messenger->answer($ctx);
        $this->messenger->show($ctx, $this->screens->code($order));
    }

    /**
     * Shows the channel screen when the customer is not subscribed yet; $next is the callback
     * that «Я подписался» continues with.
     */
    private function held(Context $ctx, string $next): bool
    {
        if ($this->gate->passes($ctx->userId())) {
            return false;
        }
        $this->messenger->answer($ctx);
        $this->messenger->show($ctx, $this->gate->screen($next));

        return true;
    }

    private function planMissing(string $planKey): bool
    {
        return ! array_key_exists($planKey, config('telegram_store.plans', []));
    }
}
