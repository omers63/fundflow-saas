<?php

declare(strict_types=1);

return [
    'driver' => env('GATEWAY_DRIVER', env('APP_ENV') === 'production' ? 'moyasar' : 'fake'),

    'currency' => env('GATEWAY_CURRENCY', 'SAR'),

    'webhook_tolerance_seconds' => (int) env('GATEWAY_WEBHOOK_TOLERANCE', 300),

    'moyasar' => [
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        'webhook_secret' => env('MOYASAR_WEBHOOK_SECRET'),
        'base_url' => env('MOYASAR_BASE_URL', 'https://api.moyasar.com/v1'),
    ],

    'hyperpay' => [
        'entity_id' => env('HYPERPAY_ENTITY_ID'),
        'access_token' => env('HYPERPAY_ACCESS_TOKEN'),
        'webhook_secret' => env('HYPERPAY_WEBHOOK_SECRET'),
        'base_url' => env('HYPERPAY_BASE_URL', 'https://eu-test.oppwa.com'),
    ],

    'sadad' => [
        'enabled' => (bool) env('SADAD_ENABLED', false),
    ],

    'fake' => [
        'webhook_secret' => env('GATEWAY_FAKE_WEBHOOK_SECRET', 'fake-gateway-secret'),
    ],
];
