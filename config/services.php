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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Tawk.to live-chat widget. Disabled by default — set TAWK_ENABLED=true and
    // TAWK_ID to your own property to enable. (Never inherit a third party's id.)
    'tawk' => [
        'enabled' => env('TAWK_ENABLED', false),
        'id' => env('TAWK_ID'),
    ],

    // Flutterwave payment gateway (HMS_PLAN.md §16). Secrets live ONLY in env
    // (C11) — never commit real keys. secret_hash verifies inbound webhooks.
    'flutterwave' => [
        'secret_key' => env('FLW_SECRET_KEY'),
        'public_key' => env('FLW_PUBLIC_KEY'),
        'encryption_key' => env('FLW_ENCRYPTION_KEY'),
        'secret_hash' => env('FLW_SECRET_HASH'),
        'base_url' => env('FLW_BASE_URL', 'https://api.flutterwave.com'),
        'currency' => env('FLW_CURRENCY', 'UGX'),
        'payment_options' => env('FLW_PAYMENT_OPTIONS', 'card,mobilemoneyuganda,banktransfer,ussd'),
        'timeout' => (int) env('FLW_TIMEOUT', 20),
    ],

    // Pesapal payment gateway (API 3.0) — used for subscription checkout
    // (App\Services\Gateway\PesapalGateway). Secrets live ONLY in env (C11).
    // Pesapal settles in UGX only; usd_to_ugx_rate is the fixed platform rate
    // plan prices (USD) are converted at — see App\Support\PlatformCurrency.
    'pesapal' => [
        'consumer_key' => env('PESAPAL_CONSUMER_KEY'),
        'consumer_secret' => env('PESAPAL_CONSUMER_SECRET'),
        'environment' => env('PESAPAL_ENVIRONMENT', 'sandbox'),   // sandbox | production
        'sandbox_url' => env('PESAPAL_SANDBOX_URL', 'https://cybqa.pesapal.com/pesapalv3'),
        'production_url' => env('PESAPAL_PRODUCTION_URL', 'https://pay.pesapal.com/v3'),
        'currency' => env('PESAPAL_CURRENCY', 'UGX'),
        'ipn_url' => env('PESAPAL_IPN_URL'),
        'callback_url' => env('PESAPAL_CALLBACK_URL'),
        'timeout' => (int) env('PESAPAL_TIMEOUT', 30),
        'usd_to_ugx_rate' => (float) env('USD_TO_UGX_RATE', 3600),
    ],

];
