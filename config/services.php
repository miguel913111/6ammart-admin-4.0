<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have a
    | conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'ryft' => [
        'public_key' => env('RYFT_PUBLIC_KEY'),
        'secret_key' => env('RYFT_SECRET_KEY'),
        'webhook_secret' => env('RYFT_WEBHOOK_SECRET'),
        'base_url' => env('RYFT_BASE_URL', 'https://api.ryftpay.com'),
        'mock_mode' => env('RYFT_MOCK_MODE', true),
    ],

    'mangopay' => [
        'client_id' => env('MANGOPAY_CLIENT_ID'),
        'api_key' => env('MANGOPAY_API_KEY'),
        'base_url' => env('MANGOPAY_BASE_URL', 'https://api.sandbox.mangopay.com'),
        'webhook_secret' => env('MANGOPAY_WEBHOOK_SECRET'),
        'mock_mode' => env('MANGOPAY_MOCK_MODE', true),
    ],

    'stripe_connect' => [
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'mock_mode' => env('STRIPE_CONNECT_MOCK_MODE', true),
    ],

    'invoicexpress' => [
        'account_name' => env('INVOICEXPRESS_ACCOUNT_NAME'),
        'api_key' => env('INVOICEXPRESS_API_KEY'),
        'base_url' => env('INVOICEXPRESS_BASE_URL'),
        'mock_mode' => env('INVOICEXPRESS_MOCK_MODE', true),
    ],

    'platform_fees' => [
        'sixammart' => (float) env('PLATFORM_FEE_6AMMART', 0.50),
        'drivemond' => (float) env('PLATFORM_FEE_DRIVEMOND', 0.15),
        'vat_rate' => (float) env('PLATFORM_FEE_VAT_RATE', 0.23),
    ],

    'default_payment_gateway' => env('PAYMENT_GATEWAY_DEFAULT', 'stripe_connect'),

    'eupago' => [
        'env' => env('EUPAGO_ENV', 'sandbox'),
        'api_key' => env('EUPAGO_API_KEY'),
        'client_id' => env('EUPAGO_CLIENT_ID'),
        'client_secret' => env('EUPAGO_CLIENT_SECRET'),
        'webhook_secret' => env('EUPAGO_WEBHOOK_SECRET'),
        'store_extern_key' => env('EUPAGO_STORE_EXTERN_KEY'),
        'delivery_extern_key' => env('EUPAGO_DELIVERY_EXTERN_KEY'),
        'platform_extern_key' => env('EUPAGO_PLATFORM_EXTERN_KEY'),
        'mock_mode' => filter_var(env('EUPAGO_MOCK_MODE', true), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('EUPAGO_BASE_URL', 'https://sandbox.eupago.pt'),
        'callback_path' => env('EUPAGO_CALLBACK_PATH', '/webhooks/eupago'),
    ],

];
