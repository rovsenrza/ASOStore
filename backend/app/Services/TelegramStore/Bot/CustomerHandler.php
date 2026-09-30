<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\TelegramStoreOrder;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\Payments\GatewayResolver;
use App\Services\TelegramStore\Payments\MockGateway;

class CustomerHandler
{
    private const ORDER = '([0-9a-z]{26})';

    public function __construct(
        private readonly CustomerScreens $screens,
        private readonly Messenger $messenger,
        private readonly OrderService $orders,
        private readonly GatewayResolver $gateways,
        private readonly AdminHandler $admin,
    ) {}

    public function message(Context $ctx, string $text): void
    {
        $command = strtolower(preg_replace('/@\w+$/', '', strtok($text, ' ') ?: ''));
        $lower = mb_strtolower($text);

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

        if (in_array($data, ['menu', 'buy', 'profile', 'orders', 'invite', 'help'], true)) {
            $this->messenger->answer($ctx);
            $this->messenger->show($ctx, match ($data) {
                'menu' => $this->screens->menu(),
                'buy' => $this->screens->plans($customer),
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
            $order = $this->orders->create($customer, $match[1]);
            $this->messenger->show($ctx, $this->screens->order($order, $customer->refresh()));

            return;
        }

        if (! preg_match('/^(order|bal|paid|mockpay|cancel|code|pay:(\w+)):'.self::ORDER.'$/', $data, $match)) {
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
            default => $this->messenger->answer($ctx),
        };
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

    private function planMissing(string $planKey): bool
    {
        return ! array_key_exists($planKey, config('telegram_store.plans', []));
    }
}
