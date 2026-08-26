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

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway (Gaslah online payments)
    |--------------------------------------------------------------------------
    | driver: null => stub in local/testing, moyasar otherwise. web_url is the
    | public base a payment link points to.
    */
    'payment' => [
        'driver' => env('PAYMENT_GATEWAY_DRIVER'),
        'web_url' => env('APP_WEB_URL', env('APP_URL', 'http://localhost')),
    ],

    'moyasar' => [
        'secret' => env('MOYASAR_SECRET_KEY'),
        'publishable' => env('MOYASAR_PUBLISHABLE_KEY'),
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
        'base_url' => env('MOYASAR_BASE_URL', 'https://api.moyasar.com/v1'),
    ],

];
