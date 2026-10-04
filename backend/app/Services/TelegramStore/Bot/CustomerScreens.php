<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\TelegramStorePromoCode;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\Payments\Checkout;
use App\Services\TelegramStore\Payments\GatewayResolver;
use App\Services\TelegramStore\StoreSettings;
use App\Services\TelegramStore\TelegramApi;

/**
 * Every customer-facing text lives here, so the sales copy can be tuned in one place.
 */
class CustomerScreens
{
    public function __construct(
        private readonly StoreSettings $settings,
        private readonly OrderService $orders,
        private readonly GatewayResolver $gateways,
        private readonly TelegramApi $api,
    ) {}

    public function welcomeText(): string
    {
        return sprintf(
            "<b>%s</b> — приложения и игры для iPhone в одной подписке.\n\n"
            ."💎 Подписка от <b>%s в месяц</b>\n"
            ."⚡ Код активации приходит сразу после оплаты\n"
            ."👥 Приглашайте друзей и получайте <b>%d%%</b> с их покупок\n\n"
            .'Выберите раздел 👇',
            e($this->brand()), Format::rub($this->lowestMonthlyPrice()), $this->settings->referralPercent(),
        );
    }

    public function menu(): Screen
    {
        return new Screen($this->welcomeText(), $this->menuRows());
    }

    /** @return list<list<array<string, string>>> */
    public function menuRows(): array
    {
        $rows = [
            [Screen::button('🛍 Купить подписку', 'buy')],
            [Screen::button('👤 Профиль', 'profile'), Screen::button('👥 Пригласить друга', 'invite')],
        ];
        $info = [Screen::button('📖 Инструкция', 'help')];
        if ($news = config('telegram_store.news_url')) {
            $info[] = Screen::link('📰 Новости', $news);
        }
        $rows[] = $info;
        if ($support = $this->supportRow()) {
            $rows[] = $support;
        }
        $rows[] = $this->legalRow();

        return $rows;
    }

    public function plans(TelegramStoreCustomer $customer, ?TelegramStorePromoCode $promo = null): Screen
    {
        $plans = $this->settings->plans();
        $best = collect(array_keys($plans))->sortByDesc(fn ($key) => $this->settings->savingPercent($key))->first();
        $bestSaving = $best ? $this->settings->savingPercent($best) : 0;

        $rows = [];
        foreach ($plans as $key => $plan) {
            $saving = $this->settings->savingPercent($key);
            $label = Format::months($plan['months']).' — '.Format::rub($plan['price']);
            if ($plan['months'] > 1) {
                $label .= ' · '.Format::rub((int) ceil($plan['price'] / $plan['months'])).'/мес';
            }
            if ($saving > 0) {
                $label .= ' · −'.$saving.'%';
            }
            $rows[] = [Screen::button(($key === $best && $bestSaving > 0 ? '🔥 ' : '').$label, 'plan:'.$key)];
        }
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        $text = ($promo ? "🎟 Промокод <b>{$promo->code}</b> активирован: <b>{$promo->label()}</b> — скидка применится к заказу.\n\n" : '')
            ."<b>💎 Подписка {$this->brandHtml()}</b>\n\n"
            ."✅ Каталог приложений и игр для iPhone\n"
            ."✅ Обновления весь срок подписки\n"
            ."✅ Мгновенная активация по коду\n"
            ."✅ Поддержка 7 дней в неделю\n";
        if ($best && $bestSaving > 0) {
            $plan = $plans[$best];
            $text .= sprintf("\n🔥 Выгоднее всего — <b>%s</b>: %s/мес вместо %s (−%d%%)\n",
                Format::months($plan['months']),
                Format::rub((int) ceil($plan['price'] / $plan['months'])),
                Format::rub($this->basePlan()['price']),
                $bestSaving,
            );
        }
        if ($customer->balance_rub > 0) {
            $text .= "\n💰 На балансе <b>".Format::rub($customer->balance_rub).'</b> — им можно оплатить заказ.';
        }
        $text .= "\n\nПокупка разовая, без автопродления. Выберите тариф 👇";

        return new Screen($text, $rows);
    }

    public function order(TelegramStoreOrder $order, TelegramStoreCustomer $customer): Screen
    {
        $plan = $this->planName($order);
        $lines = [
            "<b>🧾 Заказ #{$order->shortReference()}</b>",
            '',
            "Тариф: {$this->brandHtml()} · {$plan}",
            'Стоимость: '.Format::rub($order->price_rub),
        ];
        if ($order->discount_rub > 0 && $order->promoCode) {
            $lines[] = "Промокод {$order->promoCode->code}: −".Format::rub($order->discount_rub);
        }
        if ($order->balance_used_rub > 0) {
            $lines[] = 'Оплачено балансом: −'.Format::rub($order->balance_used_rub);
        }
        $lines[] = '<b>К оплате: '.Format::rub($order->amount_due_rub).'</b>';
        $lines[] = '';
        $lines[] = '⏳ Оплатите до <b>'.Format::time($order->expires_at).'</b> — потом заказ отменится.';

        $rows = [];
        if ($customer->balance_rub > 0 && $order->amount_due_rub > 0) {
            $rows[] = [$customer->balance_rub >= $order->amount_due_rub
                ? Screen::button('💰 Оплатить балансом ('.Format::rub($order->amount_due_rub).')', 'bal:'.$order->public_id)
                : Screen::button('💰 Списать с баланса −'.Format::rub($customer->balance_rub), 'bal:'.$order->public_id)];
        }
        $rows[] = [$order->promo_code_id
            ? Screen::button('✖️ Убрать промокод', 'unpromo:'.$order->public_id)
            : Screen::button('🎟 Ввести промокод', 'promo:'.$order->public_id)];
        $methods = $this->gateways->current()->methods($customer->telegram_user_id);
        foreach ($methods as $method => $label) {
            $rows[] = [Screen::button($label, "pay:{$method}:{$order->public_id}")];
        }
        if ($methods === []) {
            $lines[] = '';
            $lines[] = 'Онлайн-оплата подключается и скоро будет доступна.'
                .(config('telegram_store.support_url') ? ' Чтобы купить прямо сейчас, напишите в поддержку.' : '');
            if ($support = $this->supportRow()) {
                $rows[] = $support;
            }
        } else {
            $lines[] = '';
            $lines[] = 'Выберите способ оплаты 👇';
        }
        $rows[] = $this->legalRow();
        $rows[] = [Screen::button('❌ Отменить', 'cancel:'.$order->public_id), Screen::button('‹ Тарифы', 'buy')];

        return new Screen(implode("\n", $lines), $rows);
    }

    public function checkout(TelegramStoreOrder $order, Checkout $checkout): Screen
    {
        $amount = Format::rub($order->amount_due_rub);
        $back = [Screen::button('‹ Другой способ', 'order:'.$order->public_id)];

        if ($checkout->simulated) {
            return new Screen(
                "<b>🧪 Тестовая оплата · заказ #{$order->shortReference()}</b>\n\nСумма: <b>{$amount}</b>\n\n"
                .'Деньги не списываются. После симуляции заказ выполнится полностью: придёт настоящий код активации, пригласившему начислится бонус.',
                [[Screen::button('✅ Симулировать оплату', 'mockpay:'.$order->public_id)], $back],
            );
        }

        return new Screen(
            "<b>💳 Оплата заказа #{$order->shortReference()}</b>\n\nСумма: <b>{$amount}</b>\n\n"
            ."1. Нажмите «Перейти к оплате» и оплатите ровно {$amount}.\n"
            ."2. Вернитесь сюда и нажмите «Я оплатил».\n\n"
            .'Код активации придёт в этот чат сразу после проверки платежа.',
            [
                [Screen::link('Перейти к оплате →', (string) $checkout->url)],
                [Screen::button('✅ Я оплатил', 'paid:'.$order->public_id)],
                $back,
            ],
        );
    }

    public function underReview(TelegramStoreOrder $order): Screen
    {
        $rows = [];
        if ($support = $this->supportRow()) {
            $rows[] = $support;
        }
        $rows[] = $this->legalRow();
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        return new Screen(
            "⏳ <b>Проверяем оплату заказа #{$order->shortReference()}</b>\n\n"
            .'Как только платёж подтвердится, код активации придёт в этот чат. Обычно это занимает несколько минут.',
            $rows,
        );
    }

    public function paid(TelegramStoreOrder $order, string $code): Screen
    {
        $percent = $this->settings->referralPercent();
        $rows = [[Screen::link('🔓 Активировать подписку', $this->activationUrl())]];
        if ($percent > 0) {
            $rows[] = [Screen::button("👥 Пригласить друга — {$percent}% вам", 'invite')];
        }
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        return new Screen(
            "✅ <b>Оплата получена, спасибо!</b>\n\n"
            ."Заказ #{$order->shortReference()} · {$this->planName($order)}\n\n"
            ."🔑 Ваш код активации:\n<code>{$code}</code>\n<i>Нажмите на код, чтобы скопировать.</i>\n\n"
            ."Откройте страницу активации и введите код — подписка на {$order->duration_days} дней включится сразу.\n\n"
            .'Код всегда можно найти в «Профиль → Мои заказы».',
            $rows,
        );
    }

    public function promoPrompt(TelegramStoreOrder $order, ?string $error = null): Screen
    {
        return new Screen(
            ($error ? '❌ '.e($error)."\n\n" : '')
            ."🎟 <b>Промокод для заказа #{$order->shortReference()}</b>\n\nОтправьте промокод сообщением в этот чат.",
            [[Screen::button('‹ К заказу', 'order:'.$order->public_id)]],
        );
    }

    public function paidPlaceholder(TelegramStoreOrder $order): Screen
    {
        return new Screen("✅ Заказ #{$order->shortReference()} оплачен. Код активации — в сообщении ниже 👇");
    }

    public function expired(TelegramStoreOrder $order): Screen
    {
        $text = "⌛ Время на оплату заказа #{$order->shortReference()} истекло.";
        if ($order->balance_used_rub > 0) {
            $text .= "\n".Format::rub($order->balance_used_rub).' вернулись на баланс.';
        }
        $text .= "\n\nТариф «{$this->planName($order)}» по-прежнему доступен — оформить заново можно в один клик.";
        $rows = $order->plan_key && $this->settings->plan($order->plan_key)
            ? [[Screen::button('🔁 Оформить заново', 'plan:'.$order->plan_key)]]
            : [[Screen::button('🛍 Выбрать тариф', 'buy')]];

        return new Screen($text, $rows);
    }

    public function rejected(TelegramStoreOrder $order): Screen
    {
        $text = "❌ Мы не нашли оплату заказа #{$order->shortReference()}.";
        if ($order->balance_used_rub > 0) {
            $text .= "\n".Format::rub($order->balance_used_rub).' вернулись на баланс.';
        }
        $text .= "\n\nЕсли вы оплатили, напишите в поддержку и приложите чек — мы разберёмся.";
        $rows = [];
        if ($support = $this->supportRow()) {
            $rows[] = $support;
        }
        $rows[] = $this->legalRow();
        $rows[] = [Screen::button('🛍 Оформить новый заказ', 'buy')];

        return new Screen($text, $rows);
    }

    public function profile(TelegramStoreCustomer $customer): Screen
    {
        $paid = $customer->orders()->where('status', TelegramStoreOrder::PAID);
        $lastPaid = (clone $paid)->latest('paid_at')->first();
        $lines = [
            '<b>👤 Мой профиль</b>',
            '',
            "ID: <code>{$customer->telegram_user_id}</code>",
            '💰 Баланс: <b>'.Format::rub($customer->balance_rub).'</b>',
            '🛍 Покупок: '.$paid->count(),
            '👥 Приглашено друзей: '.$customer->referrals()->count(),
        ];
        if ($lastPaid) {
            $lines[] = '';
            $lines[] = 'Последняя покупка: '.$this->planName($lastPaid).', '.Format::date($lastPaid->paid_at);
        }

        return new Screen(implode("\n", $lines), [
            [Screen::button('🧾 Мои заказы', 'orders'), Screen::button('👥 Пригласить друга', 'invite')],
            [Screen::button('🛍 Купить подписку', 'buy')],
            [Screen::button('‹ Меню', 'menu')],
        ]);
    }

    public function orders(TelegramStoreCustomer $customer): Screen
    {
        $orders = $customer->orders()->latest('id')->limit(10)->get();
        if ($orders->isEmpty()) {
            return new Screen("<b>🧾 Мои заказы</b>\n\nЗаказов пока нет. Выберите тариф — код активации придёт сразу после оплаты.", [
                [Screen::button('🛍 Купить подписку', 'buy')],
                [Screen::button('‹ Профиль', 'profile')],
            ]);
        }

        $lines = ['<b>🧾 Мои заказы</b>', ''];
        $rows = [];
        foreach ($orders as $order) {
            $lines[] = sprintf('#%s · %s · %s · %s · %s',
                $order->shortReference(), $this->planName($order), Format::rub($order->price_rub),
                Format::status($order->status), Format::date($order->created_at));
            if ($order->status === TelegramStoreOrder::PAID && count($rows) < 6) {
                $rows[] = [Screen::button('🔑 Код заказа #'.$order->shortReference(), 'code:'.$order->public_id)];
            } elseif ($order->isPayable()) {
                $rows[] = [Screen::button('💳 Оплатить #'.$order->shortReference(), 'order:'.$order->public_id)];
            }
        }
        $rows[] = [Screen::button('‹ Профиль', 'profile')];

        return new Screen(implode("\n", $lines), $rows);
    }

    public function code(TelegramStoreOrder $order): Screen
    {
        $code = $this->orders->activationCode($order);

        return new Screen(
            "🔑 <b>Код заказа #{$order->shortReference()}</b>\n\n<code>{$code}</code>\n\n"
            ."Подписка: {$this->planName($order)} ({$order->duration_days} дней). Введите код на странице активации.",
            [
                [Screen::link('🔓 Активировать подписку', $this->activationUrl())],
                [Screen::button('‹ Мои заказы', 'orders')],
            ],
        );
    }

    public function invite(TelegramStoreCustomer $customer): Screen
    {
        $percent = $this->settings->referralPercent();
        $link = $this->referralLink($customer);
        $invited = $customer->referrals()->count();
        $buyers = $customer->referrals()->whereHas('orders', fn ($orders) => $orders->where('status', TelegramStoreOrder::PAID))->count();
        $share = 'https://t.me/share/url?'.http_build_query([
            'url' => $link,
            'text' => "{$this->brand()} — приложения и игры для iPhone. Подписка от ".Format::rub($this->lowestMonthlyPrice()).'/мес 👇',
        ]);

        return new Screen(
            "<b>👥 Приглашайте друзей — получайте {$percent}%</b>\n\n"
            ."С каждой покупки друга, который пришёл по вашей ссылке, вам начисляется {$percent}% на баланс. "
            ."Балансом можно оплатить подписку полностью или частично.\n\n"
            ."Ваша ссылка:\n<code>{$link}</code>\n\n"
            ."👥 Приглашено: {$invited}\n"
            ."🛍 Купили: {$buyers}\n"
            .'💸 Заработано: '.Format::rub($customer->referral_earned_rub)."\n"
            .'💰 Баланс: <b>'.Format::rub($customer->balance_rub).'</b>',
            [
                [Screen::link('📤 Поделиться ссылкой', $share)],
                [Screen::button('‹ Меню', 'menu')],
            ],
        );
    }

    public function help(): Screen
    {
        $url = e($this->activationUrl());
        $rows = [[Screen::button('🛍 Купить подписку', 'buy')]];
        if ($support = $this->supportRow()) {
            $rows[] = $support;
        }
        $rows[] = $this->legalRow();
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        return new Screen(
            "<b>📖 Как это работает</b>\n\n"
            ."1. Выберите тариф в разделе «Купить подписку».\n"
            ."2. Оплатите заказ удобным способом.\n"
            ."3. Получите код активации в этом чате.\n"
            ."4. Введите код на странице активации:\n{$url}\n\n"
            ."Код всегда доступен в «Профиль → Мои заказы».\n"
            .'Подписка не продлевается автоматически.',
            $rows,
        );
    }

    public function referralJoined(int $percent): Screen
    {
        return new Screen(
            "🎉 По вашей ссылке присоединился новый пользователь!\n\nВы получите {$percent}% с каждой его покупки на баланс.",
            [[Screen::button('👥 Мои приглашения', 'invite')]],
        );
    }

    public function referralBonus(int $bonus, int $balance): Screen
    {
        return new Screen(
            '💸 <b>+'.Format::rub($bonus)." на баланс!</b>\n\nВаш друг оформил подписку. "
            .'Сейчас на балансе '.Format::rub($balance).' — оплатите им свою подписку.',
            [[Screen::button('🛍 Купить подписку', 'buy'), Screen::button('👥 Приглашения', 'invite')]],
        );
    }

    public function balanceGift(int $amount, int $balance): Screen
    {
        return new Screen(
            '🎁 <b>Вам начислено '.Format::rub($amount)."</b> на баланс.\n\nБаланс: ".Format::rub($balance).' — используйте его при оплате подписки.',
            [[Screen::button('🛍 Купить подписку', 'buy')]],
        );
    }

    public function referralLink(TelegramStoreCustomer $customer): string
    {
        return 'https://t.me/'.$this->api->botUsername().'?start=ref_'.$customer->referral_code;
    }

    public function planName(TelegramStoreOrder $order): string
    {
        $plan = $order->plan_key ? $this->settings->plan($order->plan_key) : null;

        return $plan ? Format::months($plan['months']) : $order->duration_days.' дней';
    }

    public function activationUrl(): string
    {
        return config('telegram_store.activation_url') ?: rtrim((string) config('app.url'), '/').'/activate.html';
    }

    /** @return list<array<string, string>>|null */
    private function supportRow(): ?array
    {
        $url = config('telegram_store.support_url');

        return $url ? [Screen::link('💬 Поддержка', $url)] : null;
    }

    /**
     * Privacy policy and user agreement on the site; shown wherever support is offered.
     *
     * @return list<array<string, string>>
     */
    private function legalRow(): array
    {
        $site = rtrim((string) config('app.url'), '/');

        return [
            Screen::link('🔒 Конфиденциальность', $site.'/privacy.html'),
            Screen::link('📄 Соглашение', $site.'/terms.html'),
        ];
    }

    private function brand(): string
    {
        return (string) config('telegram_store.brand', 'Ru App Store');
    }

    private function brandHtml(): string
    {
        return e($this->brand());
    }

    /** @return array{months: int, days: int, price: int} */
    private function basePlan(): array
    {
        return collect($this->settings->plans())->sortBy('months')->first();
    }

    private function lowestMonthlyPrice(): int
    {
        return (int) collect($this->settings->plans())->map(fn ($plan) => (int) ceil($plan['price'] / $plan['months']))->min();
    }
}
