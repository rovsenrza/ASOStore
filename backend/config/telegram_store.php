<?php

$ids = fn (string $key) => array_values(array_filter(array_map('trim', explode(',', (string) env($key, '')))));

return [
    'token' => env('TELEGRAM_STORE_BOT_TOKEN'),
    // Optional: skips the getMe lookup used to build referral links.
    'bot_username' => env('TELEGRAM_STORE_BOT_USERNAME'),
    'brand' => env('TELEGRAM_STORE_BRAND', 'Ru AppStore'),

    // Mock mode: testers simulate a payment and the order completes for real
    // (activation code, referral bonus). Everyone else is told payments open soon.
    'mock_payments' => filter_var(env('TELEGRAM_STORE_MOCK_PAYMENTS', false), FILTER_VALIDATE_BOOL),
    'admin_ids' => $ids('TELEGRAM_STORE_ADMIN_IDS'),
    'tester_ids' => $ids('TELEGRAM_STORE_TESTER_IDS'),

    // Manual mode: each configured link is a payment method; an admin confirms the payment.
    'payments' => [
        'card' => env('TELEGRAM_STORE_CARD_URL'),
        'sbp' => env('TELEGRAM_STORE_SBP_URL'),
        'paypal' => env('TELEGRAM_STORE_PAYPAL_URL'),
    ],

    'order_ttl_minutes' => (int) env('TELEGRAM_STORE_ORDER_TTL_MINUTES', 30),
    'display_timezone' => env('TELEGRAM_STORE_TIMEZONE', 'Europe/Moscow'),

    // Defaults; admins change prices and the referral share from the bot (telegram_store_settings).
    'plans' => [
        'month1' => ['months' => 1, 'days' => 30, 'price' => 590],
        'month6' => ['months' => 6, 'days' => 180, 'price' => 1770],
        'month12' => ['months' => 12, 'days' => 365, 'price' => 2360],
    ],
    'referral_percent' => 15,

    'support_url' => env('TELEGRAM_STORE_SUPPORT_URL'),
    'news_url' => env('TELEGRAM_STORE_NEWS_URL'),
    'activation_url' => env('TELEGRAM_STORE_ACTIVATION_URL'),
    'welcome_banner' => env('TELEGRAM_STORE_WELCOME_BANNER', base_path('../front/public/assets/banners/ruappstore-telegram-welcome.jpg')),
];
