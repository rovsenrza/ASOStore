<?php

namespace App\Services\TelegramStore\Bot;

use App\Models\Subscription;
use App\Models\TelegramStoreCustomer;
use App\Models\TelegramStoreOrder;
use App\Models\TelegramStorePromoCode;
use App\Models\User;
use App\Services\TelegramStore\OrderService;
use App\Services\TelegramStore\Payments\Checkout;
use App\Services\TelegramStore\Payments\GatewayResolver;
use App\Services\TelegramStore\AccountLinker;
use App\Services\TelegramStore\RenewalAccounts;
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
        private readonly RenewalAccounts $accounts,
    ) {}

    public function welcomeText(): string
    {
        if ($override = $this->settings->welcomeText()) {
            return $override;
        }

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
        $hidden = $this->settings->hiddenMenuItems();
        $shown = fn (string $key) => ! in_array($key, $hidden, true);

        $rows = [
            [Screen::button('🛍 Купить подписку', 'buy')],
            [Screen::button('🔄 Продлить подписку', 'renew')],
        ];
        $account = array_values(array_filter([
            $shown('profile') ? Screen::button('👤 Профиль', 'profile') : null,
            $shown('invite') ? Screen::button('👥 Пригласить друга', 'invite') : null,
        ]));
        if ($account !== []) {
            $rows[] = $account;
        }

        // Buttons the admin added from the web panel, two per row.
        $custom = [];
        foreach ($this->settings->botBlocks() as $block) {
            if (($block['enabled'] ?? true) && ($block['in_menu'] ?? true)) {
                $custom[] = Screen::button((string) $block['label'], 'cb:'.$block['id']);
            }
        }
        foreach (array_chunk($custom, 2) as $pair) {
            $rows[] = $pair;
        }

        $info = $shown('help') ? [Screen::button('📖 Инструкция', 'help')] : [];
        if ($shown('news') && ($news = config('telegram_store.news_url'))) {
            $info[] = Screen::link('📰 Новости', $news);
        }
        if ($info !== []) {
            $rows[] = $info;
        }
        if ($shown('support') && ($support = $this->supportRow())) {
            $rows[] = $support;
        }
        if ($shown('legal')) {
            $rows[] = $this->legalRow();
        }

        return $rows;
    }

    /** A page the admin added: its text, its link button if any, and the way back. */
    public function block(array $block): Screen
    {
        $rows = [];
        if (! empty($block['url'])) {
            $rows[] = [Screen::link((string) ($block['url_label'] ?: 'Открыть'), (string) $block['url'])];
        }
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        return new Screen((string) $block['text'], $rows);
    }

    /**
     * «Купить подписку». A buyer who already has an account picks first: extend it, or a new account
     * for another iPhone. Without this many bought a second code and registered a second account.
     */
    public function buy(TelegramStoreCustomer $customer, ?TelegramStorePromoCode $promo = null): Screen
    {
        $accounts = $this->accounts->for($customer);
        if ($accounts->isEmpty()) {
            return $this->intent($promo);
        }

        $rows = $accounts->map(fn (User $user) => [Screen::button(
            '🔄 Продлить '.RenewalAccounts::maskEmail($user->email).' · '.$this->accessLine($user),
            'rna:'.$user->public_id,
        )])->all();
        $rows[] = [Screen::button('🔗 Продлить другой аккаунт', 'link')];
        $rows[] = [Screen::button('➕ Новый аккаунт — для другого iPhone', 'buynew')];
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        return new Screen(
            ($promo ? "🎟 Промокод <b>{$promo->code}</b> активирован: <b>{$promo->label()}</b> — скидка применится к заказу.\n\n" : '')
            ."<b>🛍 Покупка подписки</b>\n\n"
            .($accounts->count() === 1 ? 'У вас уже есть аккаунт' : 'У вас уже есть аккаунты').". Чтобы продлить подписку на том же iPhone, выберите аккаунт — новые дни прибавятся к текущему сроку, код вводить не нужно.\n\n"
            .'Новый аккаунт нужен только для <b>другого</b> iPhone.',
            $rows,
        );
    }

    /**
     * The first question to a buyer the bot knows no account of: is this a new iPhone, or an
     * extension? A new purchase gives a code and a registration; an extension only adds days to
     * the account that iPhone already has, so the two must not look alike. With a plan picked
     * already (a link from the site), the answers carry it.
     */
    public function intent(?TelegramStorePromoCode $promo = null, ?string $planKey = null): Screen
    {
        $plan = $planKey !== null ? ($this->settings->plans()[$planKey] ?? null) : null;

        return new Screen(
            ($promo ? "🎟 Промокод <b>{$promo->code}</b> активирован: <b>{$promo->label()}</b> — скидка применится к заказу.\n\n" : '')
            .($plan ? '<b>'.e($this->planLabel($planKey, $plan))."</b>\n\n" : "<b>🛍 Подписка {$this->brandHtml()}</b>\n\n")
            ."Ru App Store уже установлен на вашем iPhone?\n\n"
            ."🆕 <b>Нет, покупаю впервые</b> — пришлём код и поможем зарегистрировать iPhone.\n\n"
            .'🔄 <b>Да, продлеваю</b> — дни прибавятся к вашему аккаунту. iPhone заново регистрировать не нужно, код вводить не нужно.',
            [
                [Screen::button('🆕 Нет, покупаю впервые', $plan ? 'pln:'.$planKey : 'buynew')],
                [Screen::button('🔄 Да, продлеваю подписку', $plan ? 'lnk:'.$planKey : 'renew')],
                [Screen::button('‹ Меню', 'menu')],
            ],
        );
    }

    /** Asks for the account's email, then for the code sent to it. */
    public function linkPrompt(string $step, ?string $error = null): Screen
    {
        $text = $step === 'email'
            ? "<b>🔗 Найдём ваш аккаунт</b>\n\nНапишите сообщением <b>эл. почту</b>, с которой вы входите в Ru App Store. Мы отправим на неё код подтверждения — так продлить аккаунт сможете только вы."
            : "<b>📩 Проверьте почту</b>\n\nЕсли аккаунт с такой почтой есть, мы отправили на неё письмо с кодом из <b>6 цифр</b> (посмотрите и в «Спам»). Напишите код сообщением — он действует ".AccountLinker::CODE_MINUTES.' минут.';
        $rows = [];
        if ($step === 'code') {
            $rows[] = [Screen::button('✏️ Указать другую почту', 'link')];
        }
        $rows[] = [Screen::link('🌐 Продлить на сайте', $this->renewUrl())];
        if ($support = $this->supportRow()) {
            $rows[] = $support;
        }
        $rows[] = [Screen::button('‹ Отмена', 'menu')];

        return new Screen(($error ? '⚠️ '.e($error)."\n\n" : '').$text, $rows);
    }

    /**
     * A paid code nobody redeemed. One tap puts it on the buyer's account; the other answers
     * cover an account the bot does not know yet and a code meant for a new iPhone.
     */
    public function unusedCode(TelegramStoreCustomer $customer, TelegramStoreOrder $order, ?string $continue = null, bool $reminder = false): Screen
    {
        $rows = $this->accounts->for($customer)->map(fn (User $user) => [Screen::button(
            '✅ Зачислить на '.RenewalAccounts::maskEmail($user->email).' · '.$this->accessLine($user),
            "apl:{$order->public_id}:{$user->public_id}",
        )])->all();
        $rows[] = [Screen::button($rows === [] ? '🔄 Зачислить на мой аккаунт' : '🔗 Зачислить на другой аккаунт', 'lnu:'.$order->public_id)];
        $rows[] = [Screen::button('🆕 Это для нового iPhone — показать код', 'code:'.$order->public_id)];
        if ($continue !== null) {
            $rows[] = [Screen::button('🛍 Купить ещё одну подписку', $continue)];
        }
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        return new Screen(
            ($reminder ? '👋 Напоминаем: ' : '🎟 ')."<b>у вас есть оплаченный код, который ещё не использован</b>\n\n"
            ."Заказ #{$order->shortReference()} · {$this->planName($order)} ({$order->duration_days} дней) · оплачен ".Format::date($order->paid_at)."\n\n"
            ."Если Ru App Store уже стоит на вашем iPhone, просто зачислите эти дни на свой аккаунт: они прибавятся к текущему сроку, код вводить не нужно.\n\n"
            .'Если код куплен для нового iPhone — откройте его и активируйте на сайте.',
            $rows,
        );
    }

    /** A plan picked by a buyer who has an account: extend which one, or a new account. */
    public function planFor(TelegramStoreCustomer $customer, string $planKey): Screen
    {
        $plan = $this->settings->plans()[$planKey];
        $rows = $this->accounts->for($customer)->map(fn (User $user) => [Screen::button(
            '🔄 Продлить '.RenewalAccounts::maskEmail($user->email).' · '.$this->accessLine($user),
            "rnw:{$planKey}:{$user->public_id}",
        )])->all();
        $rows[] = [Screen::button('➕ Новый аккаунт — для другого iPhone', 'pln:'.$planKey)];
        $rows[] = [Screen::button('‹ Тарифы', 'buy')];

        return new Screen(
            '<b>'.e($this->planLabel($planKey, $plan))."</b>\n\n"
            .'Продлить ваш аккаунт или купить подписку для другого iPhone? При продлении новые дни прибавятся к текущему сроку сами, код вводить не нужно.',
            $rows,
        );
    }

    /** The two ways to register a new account's iPhone, at the same price. */
    public function registration(string $planKey): Screen
    {
        $plan = $this->settings->plans()[$planKey];

        return new Screen(
            '<b>'.e($this->planLabel($planKey, $plan))."</b>\n\n"
            ."<b>Новый аккаунт: как зарегистрировать iPhone?</b> Цена одинаковая.\n\n"
            ."Если вы <b>продлеваете</b> подписку, выбирать не нужно: ваш iPhone уже зарегистрирован — нажмите «У меня уже есть аккаунт».\n\n"
            ."⚡ <b>Мгновенно</b> — приложения можно ставить сразу после установки профиля. Сертификат временный: уведомления MAX и VK не приходят, и Apple может его отозвать — тогда напишите в поддержку.\n\n"
            .'🛡 <b>Аккаунт разработчика</b> — регистрация в нашем аккаунте, Apple обрабатывает её до 72 часов. Уведомления MAX и VK работают.',
            [
                [Screen::button('⚡ Мгновенно', 'reg:ins:'.$planKey)],
                [Screen::button('🛡 Аккаунт разработчика (до 72 ч)', 'reg:std:'.$planKey)],
                // Someone extending an account this chat does not know: no new registration at all.
                [Screen::button('🔄 У меня уже есть аккаунт — продлить', 'ext:'.$planKey)],
                [Screen::button('‹ Тарифы', 'buy')],
            ],
        );
    }

    public function plans(TelegramStoreCustomer $customer, ?TelegramStorePromoCode $promo = null, bool $newAccount = false, bool $extend = false): Screen
    {
        $plans = $this->settings->plans();
        $best = collect(array_keys($plans))->sortByDesc(fn ($key) => $this->settings->savingPercent($key))->first();
        $bestSaving = $best ? $this->settings->savingPercent($best) : 0;

        $rows = [];
        foreach ($plans as $key => $plan) {
            $rows[] = [Screen::button(($key === $best && $bestSaving > 0 ? '🔥 ' : '').$this->planLabel($key, $plan), ($extend ? 'ext:' : ($newAccount ? 'pln:' : 'plan:')).$key)];
        }
        $rows[] = [Screen::button($extend ? '‹ Назад' : '‹ Меню', $extend ? 'renew' : ($newAccount ? 'buy' : 'menu'))];

        $text = ($promo ? "🎟 Промокод <b>{$promo->code}</b> активирован: <b>{$promo->label()}</b> — скидка применится к заказу.\n\n" : '')
            .($newAccount ? ($this->accounts->for($customer)->isEmpty()
                ? "🆕 <b>Новая подписка</b> — для iPhone, на котором ещё нет Ru App Store. Код придёт в этот чат.\n\n"
                : "➕ <b>Новый аккаунт</b> — подписка для другого iPhone. Код придёт в этот чат.\n\n") : '')
            .($extend ? "🔄 <b>Продление</b> — код придёт в этот чат. Введите его в своём аккаунте на странице активации: новые дни прибавятся к сроку, iPhone заново регистрировать не нужно.\n\n" : '')
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

    /** «Продлить подписку»: straight to the terms when the customer has one known account. */
    public function renew(TelegramStoreCustomer $customer): Screen
    {
        $accounts = $this->accounts->for($customer);
        if ($accounts->count() === 1) {
            return $this->renewPlans($customer, $accounts->first());
        }

        if ($accounts->isEmpty()) {
            $rows = [
                [Screen::button('🔗 Найти мой аккаунт по эл. почте', 'link')],
                [Screen::link('🌐 Продлить на сайте', $this->renewUrl())],
                [Screen::button('🎟 Получить код в чат', 'buyext')],
            ];
            if ($support = $this->supportRow()) {
                $rows[] = $support;
            }
            $rows[] = [Screen::button('‹ Меню', 'menu')];

            return new Screen(
                "<b>🔄 Продление подписки</b>\n\n"
                ."Продление прибавляет дни к вашему аккаунту. iPhone остаётся зарегистрированным, заново ждать регистрацию не нужно.\n\n"
                ."<b>Найти аккаунт по почте</b> — подтвердите эл. почту аккаунта кодом из письма, выберите срок и оплатите. Подписка продлится сама.\n\n"
                ."<b>На сайте</b> — войдите в аккаунт, выберите срок и оплатите.\n\n"
                .'<b>Код в чат</b> — если нет доступа к почте: оплатите здесь и введите код в своём аккаунте на странице активации.',
                $rows,
            );
        }

        $rows = $accounts->map(fn (User $user) => [Screen::button(
            RenewalAccounts::maskEmail($user->email).' · '.$this->accessLine($user),
            'rna:'.$user->public_id,
        )])->all();
        $rows[] = [Screen::button('🔗 Другой аккаунт', 'link')];
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        return new Screen("<b>🔄 Продление подписки</b>\n\nКакой аккаунт продлить? 👇", $rows);
    }

    public function renewPlans(TelegramStoreCustomer $customer, User $account): Screen
    {
        $rows = [];
        foreach ($this->settings->plans() as $key => $plan) {
            $rows[] = [Screen::button($this->planLabel($key, $plan), "rnw:{$key}:{$account->public_id}")];
        }
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        $text = "<b>🔄 Продление подписки</b>\n\n"
            .'Аккаунт: <b>'.e(RenewalAccounts::maskEmail($account->email))."</b>\n"
            .'Подписка: <b>'.$this->accessLine($account)."</b>\n\n"
            .'После оплаты подписка продлится сама, код вводить не нужно. ';
        $text .= $account->activeSubscription ? 'Новые дни прибавятся к текущему сроку.' : 'Доступ включится сразу.';
        if ($customer->balance_rub > 0) {
            $text .= "\n\n💰 На балансе <b>".Format::rub($customer->balance_rub).'</b> — им можно оплатить заказ.';
        }

        return new Screen($text."\n\nВыберите срок 👇", $rows);
    }

    public function renewed(TelegramStoreOrder $order, Subscription $subscription, ?User $account = null): Screen
    {
        $rows = [[Screen::link('👤 Мой аккаунт', rtrim((string) config('app.url'), '/').'/account.html')]];
        $rows[] = [Screen::button('‹ Меню', 'menu')];
        $until = $subscription->ends_at ? 'до <b>'.Format::date($subscription->ends_at).'</b>' : '<b>без ограничения срока</b>';
        $device = $subscription->device;
        if ($order->add_device && $device !== null && ! $device->isEnrolled()) {
            // A new iPhone was paid for: it is connected by installing the profile on that iPhone.
            return new Screen(
                "✅ <b>Новый iPhone добавлен в аккаунт!</b>\n\n"
                ."Заказ #{$order->shortReference()} · {$this->planName($order)}\n"
                .'Аккаунт: '.e(RenewalAccounts::maskEmail(($account ?? $order->renewUser)?->email))."\n"
                ."Доступ для него действует {$until}.\n\n"
                ."<b>Подключите его:</b> на новом iPhone откройте ruappstore.com в Safari, войдите в этот аккаунт и нажмите «Подключить iPhone» в разделе «Мои устройства».",
                [[Screen::link('📱 Мои устройства', rtrim((string) config('app.url'), '/').'/devices.html')], [Screen::button('‹ Меню', 'menu')]],
            );
        }

        return new Screen(
            "✅ <b>Подписка продлена!</b>\n\n"
            ."Заказ #{$order->shortReference()} · {$this->planName($order)}\n"
            .'Аккаунт: '.e(RenewalAccounts::maskEmail(($account ?? $order->renewUser)?->email))."\n"
            .($device?->isEnrolled() ? 'iPhone: '.e(\App\Services\Devices\CustomerDevices::model($device->product) ?? 'iPhone').' ••'.e((string) $device->udid_hint)."\n" : '')
            ."Подписка действует {$until}.\n\n"
            .'Код вводить не нужно, откройте Ru App Store на iPhone.',
            $rows,
        );
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
        if ($order->isRenewal() && $order->add_device) {
            array_splice($lines, 2, 0, ['➕ Новый iPhone в аккаунт '.e(RenewalAccounts::maskEmail($order->renewUser?->email))]);
            if (OrderService::isInstant($order)) {
                array_splice($lines, 4, 0, ['Регистрация: ⚡ мгновенная']);
            }
        } elseif ($order->isRenewal()) {
            $device = $order->renewDevice;
            array_splice($lines, 2, 0, ['🔄 Продление '.($device ? e(\App\Services\Devices\CustomerDevices::model($device->product) ?? 'iPhone').' ••'.e((string) $device->udid_hint).' · ' : 'аккаунта ').e(RenewalAccounts::maskEmail($order->renewUser?->email))]);
        } elseif (OrderService::isInstant($order)) {
            array_splice($lines, 3, 0, ['Регистрация: ⚡ мгновенная']);
        }
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
        $rows[] = [Screen::button('❌ Отменить', 'cancel:'.$order->public_id), Screen::button('‹ Тарифы', $order->isRenewal() ? 'renew' : 'buy')];

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

        if (! $checkout->needsConfirmation) {
            return new Screen(
                "<b>💳 Оплата заказа #{$order->shortReference()}</b>\n\nСумма: <b>{$amount}</b>\n\n"
                ."Нажмите «Оплатить» — откроется защищённая страница платёжного сервиса Platega, там можно оплатить картой или через СБП.\n\n"
                .($order->isRenewal()
                    ? 'Подписка продлится автоматически, как только платёж подтвердится. Если подтверждение не пришло за пару минут, нажмите «Проверить оплату».'
                    : 'Код активации придёт в этот чат автоматически, как только платёж подтвердится. Если код не пришёл за пару минут, нажмите «Проверить оплату».'),
                [
                    [Screen::link("💳 Оплатить {$amount}", (string) $checkout->url)],
                    [Screen::button('🔄 Проверить оплату', 'paid:'.$order->public_id)],
                    $this->legalRow(),
                    [Screen::button('‹ К заказу', 'order:'.$order->public_id)],
                ],
            );
        }

        return new Screen(
            "<b>💳 Оплата заказа #{$order->shortReference()}</b>\n\nСумма: <b>{$amount}</b>\n\n"
            ."1. Нажмите «Перейти к оплате» и оплатите ровно {$amount}.\n"
            ."2. Вернитесь сюда и нажмите «Я оплатил».\n\n"
            .($order->isRenewal() ? 'Подписка продлится сразу после проверки платежа.' : 'Код активации придёт в этот чат сразу после проверки платежа.'),
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
            .($order->isRenewal()
                ? 'Как только платёж подтвердится, подписка продлится автоматически. Обычно это занимает несколько минут.'
                : 'Как только платёж подтвердится, код активации придёт в этот чат. Обычно это занимает несколько минут.'),
            $rows,
        );
    }

    public function paid(TelegramStoreOrder $order, string $code): Screen
    {
        $percent = $this->settings->referralPercent();
        $rows = [
            [Screen::link('🔓 Активировать подписку', $this->activationUrl())],
            [Screen::button('🔄 У меня уже есть аккаунт — зачислить на него', 'use:'.$order->public_id)],
        ];
        if ($percent > 0) {
            $rows[] = [Screen::button("👥 Пригласить друга — {$percent}% вам", 'invite')];
        }
        $rows[] = [Screen::button('‹ Меню', 'menu')];

        return new Screen(
            "✅ <b>Оплата получена, спасибо!</b>\n\n"
            ."Заказ #{$order->shortReference()} · {$this->planName($order)}\n\n"
            ."🔑 Ваш код активации:\n<code>{$code}</code>\n<i>Нажмите на код, чтобы скопировать.</i>\n\n"
            ."Откройте страницу активации и введите код — подписка на {$order->duration_days} дней включится сразу.\n\n"
            .(OrderService::isInstant($order) ? "⚡ Регистрация мгновенная: после установки профиля на iPhone приложения можно ставить сразу.\n\n" : '')
            ."⚠️ Уже есть аккаунт на этом iPhone? Не регистрируйте новый: нажмите «У меня уже есть аккаунт» — дни прибавятся к текущему сроку, код вводить не придётся.\n\n"
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
        return new Screen($order->isRenewal()
            ? "✅ Заказ #{$order->shortReference()} оплачен. Подробности — в сообщении ниже 👇"
            : "✅ Заказ #{$order->shortReference()} оплачен. Код активации — в сообщении ниже 👇");
    }

    public function expired(TelegramStoreOrder $order): Screen
    {
        $text = "⌛ Время на оплату заказа #{$order->shortReference()} истекло.";
        if ($order->balance_used_rub > 0) {
            $text .= "\n".Format::rub($order->balance_used_rub).' вернулись на баланс.';
        }
        $text .= "\n\nТариф «{$this->planName($order)}» по-прежнему доступен — оформить заново можно в один клик.";
        $rows = $order->isRenewal()
            ? [[Screen::button('🔄 Продлить подписку', 'renew')]]
            : ($order->plan_key && $this->settings->plan($order->plan_key)
            ? [[Screen::button('🔁 Оформить заново', 'plan:'.$order->plan_key)]]
            : [[Screen::button('🛍 Выбрать тариф', 'buy')]]);

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
        $rows[] = [$order->isRenewal() ? Screen::button('🔄 Продлить подписку', 'renew') : Screen::button('🛍 Оформить новый заказ', 'buy')];

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
            [Screen::button('🔄 Продлить подписку', 'renew')],
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
        if ($order->isRenewal() && $this->orders->codeWasRedeemed($order)) {
            return new Screen(
                "✅ <b>Заказ #{$order->shortReference()}</b>\n\nЭтот заказ уже продлил подписку аккаунта ".e(RenewalAccounts::maskEmail($order->renewUser?->email)).'. Код вводить не нужно.',
                [[Screen::button('‹ Мои заказы', 'orders')]],
            );
        }
        $code = $this->orders->activationCode($order);

        return new Screen(
            "🔑 <b>Код заказа #{$order->shortReference()}</b>\n\n<code>{$code}</code>\n\n"
            ."Подписка: {$this->planName($order)} ({$order->duration_days} дней). Введите код на странице активации.",
            [
                [Screen::link('🔓 Активировать подписку', $this->activationUrl())],
                ...($this->orders->codeWasRedeemed($order) ? [] : [[Screen::button('🔄 Зачислить на мой аккаунт', 'use:'.$order->public_id)]]),
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
        $rows = [[Screen::button('🛍 Купить подписку', 'buy')], [Screen::button('🔄 Продлить подписку', 'renew')]];
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
            ."Код всегда доступен в «Профиль → Мои заказы».\n\n"
            .'Подписка не продлевается автоматически: когда срок подойдёт к концу, нажмите «Продлить подписку» и оплатите. Новые дни прибавятся сами.',
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

    /** @param array{months: int, days: int, price: int} $plan */
    private function planLabel(string $key, array $plan): string
    {
        $saving = $this->settings->savingPercent($key);
        $label = Format::months($plan['months']).' — '.Format::rub($plan['price']);
        if ($plan['months'] > 1) {
            $label .= ' · '.Format::rub((int) ceil($plan['price'] / $plan['months'])).'/мес';
        }

        return $saving > 0 ? $label.' · −'.$saving.'%' : $label;
    }

    /** «активна до 01.12.2026» or «закончилась 05.11.2026». */
    private function accessLine(User $user): string
    {
        $active = $user->activeSubscription;
        if ($active) {
            return $active->ends_at ? 'активна до '.Format::date($active->ends_at) : 'активна без ограничения срока';
        }
        $end = $this->accounts->lastEnd($user);

        return $end ? 'закончилась '.Format::date($end) : 'не активна';
    }

    private function renewUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/renew.html';
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
        return $this->settings->brand();
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
