<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Platega (https://docs.platega.io): card/SBP payments for store orders on the website and in
    // the bot. Both keys set = online payment is on; the dashboard's Callback URL must point to
    // https://<site>/api/v1/payments/platega/callback.
    'platega' => [
        'merchant_id' => env('PLATEGA_MERCHANT_ID'),
        'secret' => env('PLATEGA_SECRET'),
        'base_url' => env('PLATEGA_BASE_URL', 'https://app.platega.io'),
        // Empty: the payer picks the method on Platega's page. A PaymentMethodInt (2 SBP QR,
        // 11 cards, 14 SberPay …) skips that choice.
        'payment_method' => env('PLATEGA_PAYMENT_METHOD') ?: null,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
