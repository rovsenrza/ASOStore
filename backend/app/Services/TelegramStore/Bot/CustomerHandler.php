<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\TelegramStoreOrder;
use App\Models\User;
use App\Services\InstantCerts\InstantCertificates;
use App\Services\TelegramStore\AccountLinker;
use App\Services\TelegramStore\CompletedOrder;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\Payments\GatewayResolver;
use App\Services\TelegramStore\Payments\MockGateway;
use App\Services\TelegramStore\Payments\PlategaException;
use App\Services\TelegramStore\Payments\PlategaPayments;
use App\Services\TelegramStore\PromoService;
use App\Services\TelegramStore\RenewalAccounts;
use App\Services\TelegramStore\StoreSettings;
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
        private readonly PlategaPayments $platega,
        private readonly RenewalAccounts $accounts,
        private readonly StoreSettings $settings,
        private readonly InstantCertificates $instant,
        private readonly AccountLinker $linker,
    ) {}

    /** @return array<string, mixed>|null */
    private function matchBlock(string $command, string $lower): ?array
    {
        foreach ($this->settings->botBlocks() as $block) {
            if (! ($block['enabled'] ?? true)) {
                continue;
            }
            if ($command !== '' && ! empty($block['command']) && $command === '/'.ltrim(mb_strtolower((string) $block['command']), '/')) {
                return $block;
            }
            foreach ($block['keywords'] ?? [] as $keyword) {
                $keyword = mb_strtolower(trim((string) $keyword));
                if ($keyword !== '' && str_contains($lower, $keyword)) {
                    return $block;
                }
            }
        }

        return null;
    }

    public function message(Context $ctx, string $text): void
    {
        $command = strtolower(preg_replace('/@\w+$/', '', strtok($text, ' ') ?: ''));
        $lower = mb_strtolower($text);

        // An email or its confirmation code typed while finding the customer's account.
        $linking = Cache::get($this->linkStateKey($ctx));
        if (is_array($linking) && $text !== '' && ! str_starts_with($text, '/')) {
            $this->enterLink($ctx, $linking, $text);

            return;
        }
        Cache::forget($this->linkStateKey($ctx));

        // «Продлить в Telegram» on the signed-in site: the link carries the account.
        if ($command === '/start' && preg_match('/^\/start\s+acc_([A-Za-z0-9]{20,48})$/', $text, $match)) {
            $account = $this->linker->consumeSiteToken($ctx->customer, $match[1]);
            if (! $this->held($ctx, 'renew')) {
                $account ? $this->afterLink($ctx, $account, []) : $this->messenger->show($ctx, $this->screens->renew($ctx->customer));
            }

            return;
        }

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
            isset($match[1]) ? $this->orderPlan($ctx, $match[1]) : $this->messenger->show($ctx, $this->screens->buy($ctx->customer, $this->promos->remembered($ctx->userId())));

            return;
        }

        // «Продлить» from a link on the site or in a post, the command, or plain words.
        $wantsRenewal = $command === '/renew' || ($command === '/start' && preg_match('/^\/start\s+renew$/', $text) === 1) || str_contains($lower, 'продл');
        if ($wantsRenewal) {
            if (! $this->held($ctx, 'renew') && ! $this->offeredUnusedCode($ctx, 'renew2')) {
                $this->messenger->show($ctx, $this->screens->renew($ctx->customer));
            }

            return;
        }

        if (($command === '/buy' || str_contains($lower, 'купить')) && ($this->held($ctx, 'buy') || $this->offeredUnusedCode($ctx, 'buy2'))) {
            return;
        }

        // A /command or keyword of a page the admin added in the web panel.
        if (! in_array($command, ['/start', '/menu', '/buy', '/profile', '/invite', '/help'], true) && ($block = $this->matchBlock($command, $lower)) !== null) {
            $this->messenger->show($ctx, $this->screens->block($block));

            return;
        }

        match (true) {
            in_array($command, ['/start', '/menu'], true), $lower === 'меню' => $this->messenger->welcome($ctx->chatId, $ctx->customer),
            $command === '/buy', str_contains($lower, 'купить') => $this->messenger->show($ctx, $this->screens->buy($ctx->customer)),
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
        if (preg_match('/^(buy|buy2|buynew|buyext|renew|renew2|plan:\w+|pln:\w+|ext:\w+|lnk:\w+|reg:\w+:\w+(:\w+)?|rna:\w+|rnw:\w+:\w+|rnv:\w+:\w+|(order|bal|pay:\w+|paid|mockpay|promo):[0-9a-z]{26})$/', $data) === 1 && $this->held($ctx, $data)) {
            return;
        }

        if (preg_match('/^cb:([a-z0-9_-]{1,40})$/', $data, $match)) {
            $block = $this->settings->botBlock($match[1]);
            $this->messenger->answer($ctx, $block ? null : 'Этот раздел больше недоступен.', $block === null);
            $this->messenger->show($ctx, $block ? $this->screens->block($block) : $this->screens->menu());

            return;
        }

        // Buying or extending again while a paid code lies unused: offer to use that code first.
        if (in_array($data, ['buy', 'renew'], true) && $this->offeredUnusedCode($ctx, $data.'2')) {
            return;
        }
        if (in_array($data, ['buy2', 'renew2'], true)) {
            $data = rtrim($data, '2');
        }

        if (in_array($data, ['menu', 'buy', 'buynew', 'buyext', 'renew', 'profile', 'orders', 'invite', 'help'], true)) {
            Cache::forget($this->linkStateKey($ctx));
            $this->messenger->answer($ctx);
            $this->messenger->show($ctx, match ($data) {
                'menu' => $this->screens->menu(),
                'buy' => $this->screens->buy($customer, $this->promos->remembered($customer->telegram_user_id)),
                'buynew' => $this->screens->plans($customer, $this->promos->remembered($customer->telegram_user_id), true),
                'buyext' => $this->screens->plans($customer, $this->promos->remembered($customer->telegram_user_id), extend: true),
                'renew' => $this->screens->renew($customer),
                'profile' => $this->screens->profile($customer),
                'orders' => $this->screens->orders($customer),
                'invite' => $this->screens->invite($customer),
                'help' => $this->screens->help(),
            });

            return;
        }

        // Find the customer's account by its email: `link` (then the terms), `lnk:<plan>` (then an
        // order for that plan), `lnu:<order>` (then that order's unused code goes onto the account).
        if (preg_match('/^(link|lnk:(\w+)|lnu:'.self::ORDER.')$/', $data, $match)) {
            $then = match (true) {
                isset($match[3]) => ['order' => $match[3]],
                isset($match[2]) && $match[2] !== '' => ['plan' => $match[2]],
                default => [],
            };
            $this->messenger->answer($ctx);
            Cache::put($this->linkStateKey($ctx), ['step' => 'email', 'then' => $then], now()->addMinutes(30));
            $this->messenger->show($ctx, $this->screens->linkPrompt('email'));

            return;
        }

        // A paid, unused code: what to do with it.
        if (preg_match('/^use:'.self::ORDER.'$/', $data, $match)) {
            $order = $this->unusedOrder($ctx, $match[1]);
            $this->messenger->answer($ctx, $order ? null : 'Этот код уже использован.', ! $order);
            $this->messenger->show($ctx, $order ? $this->screens->unusedCode($customer, $order) : $this->screens->orders($customer));

            return;
        }

        // …onto this account.
        if (preg_match('/^apl:'.self::ORDER.':(\w+)$/', $data, $match)) {
            $order = $this->unusedOrder($ctx, $match[1]);
            $account = $this->accounts->find($customer, $match[2]);
            if (! $order || ! $account) {
                $this->messenger->answer($ctx, $order ? 'Аккаунт не найден.' : 'Этот код уже использован.', true);
                $this->messenger->show($ctx, $this->screens->orders($customer));

                return;
            }
            $this->messenger->answer($ctx);
            $this->applyUnused($ctx, $order, $account);

            return;
        }

        // One of several accounts picked: its terms.
        if (preg_match('/^rna:(\w+)$/', $data, $match)) {
            $account = $this->accounts->find($customer, $match[1]);
            $this->messenger->answer($ctx, $account ? null : 'Аккаунт не найден.', ! $account);
            $this->messenger->show($ctx, $account ? $this->screens->renewPlans($customer, $account) : $this->screens->renew($customer));

            return;
        }

        if (preg_match('/^rnw:(\w+):(\w+)$/', $data, $match)) {
            $account = $this->accounts->find($customer, $match[2]);
            if (! $account || $this->planMissing($match[1])) {
                $this->messenger->answer($ctx, 'Этот вариант больше недоступен.', true);
                $this->messenger->show($ctx, $this->screens->renew($customer));

                return;
            }
            $this->messenger->answer($ctx);
            $this->startOrder($ctx, $match[1], $account);

            return;
        }

        // One iPhone of a known account: extend that iPhone (access is paid per iPhone).
        if (preg_match('/^rnv:(\w+):([0-9a-z]{26})$/', $data, $match)) {
            $device = \App\Models\Device::query()->where('public_id', $match[2])->first();
            $account = $device ? $this->accounts->for($customer)->first(fn (User $user) => $user->id === $device->user_id) : null;
            if (! $account || $this->planMissing($match[1])) {
                $this->messenger->answer($ctx, 'Этот вариант больше недоступен.', true);
                $this->messenger->show($ctx, $this->screens->renew($customer));

                return;
            }
            $this->messenger->answer($ctx);
            $this->startOrder($ctx, $match[1], $account, OrderService::STANDARD, $device);

            return;
        }

        // plan: may still be a renewal (the buyer has an account); pln: is a new account chosen on purpose.
        if (preg_match('/^(plan|pln):(\w+)$/', $data, $match)) {
            if ($this->planMissing($match[2])) {
                $this->messenger->answer($ctx, 'Этот тариф больше недоступен.', true);
                $this->messenger->show($ctx, $this->screens->buy($customer));

                return;
            }
            $this->messenger->answer($ctx);
            $match[1] === 'pln' ? $this->askRegistration($ctx, $match[2]) : $this->orderPlan($ctx, $match[2]);

            return;
        }

        // Extending an account the bot does not know (bought on the site, or a code from an admin):
        // the code is entered in that account, whose iPhone already has its slot. No registration
        // question, and never an instant certificate.
        if (preg_match('/^ext:(\w+)$/', $data, $match)) {
            if ($this->planMissing($match[1])) {
                $this->messenger->answer($ctx, 'Этот тариф больше недоступен.', true);
                $this->messenger->show($ctx, $this->screens->renew($customer));

                return;
            }
            $this->messenger->answer($ctx);
            $this->startOrder($ctx, $match[1]);

            return;
        }

        // How the new iPhone gets registered: reg:ins (instant certificate) or reg:std (our teams).
        // With an account (reg:std:month1:<account>) the iPhone is added to that account, paid on
        // its own; without one the code starts a new account.
        if (preg_match('/^reg:(ins|std):([a-z0-9]+)(?::([0-9a-z]{26}))?$/', $data, $match)) {
            $account = isset($match[3]) ? $this->accounts->find($customer, $match[3]) : null;
            if ($this->planMissing($match[2]) || ($match[1] === 'ins' && ! $this->instant->offered()) || (isset($match[3]) && ! $account)) {
                $this->messenger->answer($ctx, 'Этот вариант больше недоступен.', true);
                $this->messenger->show($ctx, $this->screens->buy($customer));

                return;
            }
            if ($account && $account->devices()->count() >= \App\Services\Devices\DeviceSlots::limit()) {
                $this->messenger->answer($ctx, 'В аккаунте уже '.\App\Services\Devices\DeviceSlots::limit().' устройств — больше добавить нельзя.', true);

                return;
            }
            $this->messenger->answer($ctx);
            $this->startOrder($ctx, $match[2], $account, $match[1] === 'ins' ? OrderService::INSTANT : OrderService::STANDARD, null, $account !== null);

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

    /**
     * A plan picked without saying for which account. A buyer who already has an account is asked
     * first: many bought a new code when they meant to extend, and then ran two accounts.
     */
    private function orderPlan(Context $ctx, string $planKey): void
    {
        if (! $this->planMissing($planKey) && $this->accounts->for($ctx->customer)->isNotEmpty()) {
            $this->messenger->show($ctx, $this->screens->planFor($ctx->customer, $planKey));

            return;
        }
        if ($this->planMissing($planKey)) {
            $this->askRegistration($ctx, $planKey);

            return;
        }
        // Nobody the bot knows: first-time buyer, or someone extending an account bought elsewhere?
        $this->messenger->show($ctx, $this->screens->intent($this->promos->remembered($ctx->userId()), $planKey));
    }

    /** A new account: instant certificate or our developer teams, when both are on sale. */
    private function askRegistration(Context $ctx, string $planKey): void
    {
        if (! $this->planMissing($planKey) && $this->instant->offered()) {
            $this->messenger->show($ctx, $this->screens->registration($planKey));

            return;
        }
        $this->startOrder($ctx, $planKey);
    }

    /** Opens an order for the plan (a remembered promo code applies) and shows how to pay it. */
    private function startOrder(Context $ctx, string $planKey, ?User $renewFor = null, string $registration = OrderService::STANDARD, ?\App\Models\Device $device = null, bool $addDevice = false): void
    {
        if ($this->planMissing($planKey)) {
            $this->messenger->show($ctx, $this->screens->plans($ctx->customer, $this->promos->remembered($ctx->userId())));

            return;
        }
        // Extending an account without naming the iPhone: its only iPhone, when it has just one.
        if ($renewFor !== null && ! $addDevice && $device === null && $renewFor->devices()->count() === 1) {
            $device = $renewFor->devices()->first();
        }
        $order = $this->orders->create($ctx->customer, $planKey, $renewFor, $registration, $device, $addDevice);
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

    private function linkStateKey(Context $ctx): string
    {
        return 'telegram_store.customer_link_state.'.$ctx->userId();
    }

    /**
     * A message typed while finding the account: first its email, then the code from the letter.
     *
     * @param  array{step: string, then: array<string, string>}  $state
     */
    private function enterLink(Context $ctx, array $state, string $text): void
    {
        if ($state['step'] === 'email') {
            $email = trim($text);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->messenger->show($ctx, $this->screens->linkPrompt('email', 'Это не похоже на адрес эл. почты. Напишите его ещё раз, например name@mail.ru.'));

                return;
            }
            if (! $this->linker->requestCode($ctx->customer, $email)) {
                $this->messenger->show($ctx, $this->screens->linkPrompt('email', 'Слишком много попыток. Подождите полчаса или продлите подписку на сайте.'));

                return;
            }
            Cache::put($this->linkStateKey($ctx), ['step' => 'code'] + $state, now()->addMinutes(AccountLinker::CODE_MINUTES));
            $this->messenger->show($ctx, $this->screens->linkPrompt('code'));

            return;
        }

        $account = $this->linker->confirm($ctx->customer, $text);
        if ($account === null) {
            $this->messenger->show($ctx, $this->screens->linkPrompt('code', $this->linker->awaitsCode($ctx->customer)
                ? 'Код не подошёл. Проверьте письмо и напишите 6 цифр ещё раз.'
                : 'Код не подошёл или устарел. Нажмите «Указать другую почту» и запросите новый.'));

            return;
        }
        Cache::forget($this->linkStateKey($ctx));
        $this->afterLink($ctx, $account, $state['then'] ?? []);
    }

    /**
     * The account is known now: carry on with what the customer came for.
     *
     * @param  array<string, string>  $then
     */
    private function afterLink(Context $ctx, User $account, array $then): void
    {
        if (isset($then['order']) && ($order = $this->unusedOrder($ctx, $then['order']))) {
            $this->applyUnused($ctx, $order, $account);

            return;
        }
        if (isset($then['plan']) && ! $this->planMissing($then['plan'])) {
            $this->startOrder($ctx, $then['plan'], $account);

            return;
        }
        // A code already paid for goes first: no need to pay again.
        if ($order = $this->orders->unusedCodes($ctx->customer)->first()) {
            $this->messenger->show($ctx, $this->screens->unusedCode($ctx->customer, $order, 'rna:'.$account->public_id));

            return;
        }
        $this->messenger->show($ctx, $this->screens->renewPlans($ctx->customer, $account));
    }

    /** The customer's own paid order whose code is still unused, or null. */
    private function unusedOrder(Context $ctx, string $reference): ?TelegramStoreOrder
    {
        return $this->orders->unusedCodes($ctx->customer)->first(fn (TelegramStoreOrder $order) => $order->public_id === strtolower($reference));
    }

    private function applyUnused(Context $ctx, TelegramStoreOrder $order, User $account): void
    {
        try {
            $subscription = $this->orders->applyCode($order, $account);
        } catch (\Throwable $exception) {
            report($exception);
            $this->messenger->show($ctx, $this->screens->code($order));

            return;
        }
        $this->messenger->show($ctx, $this->screens->renewed($order, $subscription, $account));
    }

    /** Shows the unused-code screen instead of selling another subscription; false when there is none. */
    private function offeredUnusedCode(Context $ctx, string $continue): bool
    {
        $order = $this->orders->unusedCodes($ctx->customer)->first();
        if ($order === null) {
            return false;
        }
        $this->messenger->answer($ctx);
        $this->messenger->show($ctx, $this->screens->unusedCode($ctx->customer, $order, $continue));

        return true;
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
        $this->orders->selectMethod($order, $method, $gateway->name());
        try {
            $checkout = $gateway->checkout($order, $method);
        } catch (PlategaException $exception) {
            report($exception);
            $this->messenger->answer($ctx, 'Платёжная система не ответила. Попробуйте ещё раз через минуту.', true);

            return;
        }
        $this->messenger->answer($ctx);
        $this->messenger->show($ctx, $this->screens->checkout($order, $checkout));
    }

    private function reportPaid(Context $ctx, TelegramStoreOrder $order): void
    {
        // Platega: ask it now instead of waiting for the callback.
        if ($order->payment_provider === PlategaPayments::PROVIDER && $order->payment_reference) {
            $this->checkPayment($ctx, $order);

            return;
        }
        if ($order->status === TelegramStoreOrder::REVIEW) {
            $this->messenger->answer($ctx, $order->isRenewal() ? 'Уже проверяем — подтверждение придёт сюда.' : 'Уже проверяем — код придёт сюда.');

            return;
        }
        if (GatewayResolver::mock() || ! $this->orders->markForReview($order)) {
            $this->showOrder($ctx, $order);

            return;
        }
        $this->messenger->answer($ctx);
        $this->messenger->show($ctx, $this->screens->underReview($order->refresh()));
        $this->admin->notifyReview($order);
    }

    private function simulatePayment(Context $ctx, TelegramStoreOrder $order): void
    {
        if (! GatewayResolver::mock() || ! MockGateway::isTester($ctx->userId())) {
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

    private function checkPayment(Context $ctx, TelegramStoreOrder $order): void
    {
        if ($order->status === TelegramStoreOrder::PAID) {
            $this->messenger->answer($ctx, $order->isRenewal() ? 'Заказ уже оплачен, подписка продлена.' : 'Заказ уже оплачен — код в сообщении выше.', true);

            return;
        }
        // Settling delivers the code itself, so only the placeholder is shown here.
        $completed = $this->platega->check($order);
        if ($completed) {
            $this->messenger->answer($ctx, 'Оплата получена ✅');
            $this->messenger->show($ctx, $this->screens->paidPlaceholder($completed->order));

            return;
        }
        $order->refresh();
        $this->messenger->answer($ctx, $order->status === TelegramStoreOrder::PAID
            ? ($order->isRenewal() ? 'Заказ уже оплачен, подписка продлена.' : 'Заказ уже оплачен — код в сообщении выше.')
            : ($order->isRenewal()
                ? 'Оплата пока не поступила. Если вы уже оплатили, подписка продлится автоматически в течение пары минут.'
                : 'Оплата пока не поступила. Если вы уже оплатили, код придёт сюда автоматически в течение пары минут.'), true);
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
