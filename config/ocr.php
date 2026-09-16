<?php

declare(strict_types=1);

return [
    'driver' => env('OCR_DRIVER', 'fake'),

    'cloud' => [
        'endpoint' => env('OCR_CLOUD_ENDPOINT'),
        'api_key' => env('OCR_CLOUD_API_KEY'),
        'timeout' => (int) env('OCR_CLOUD_TIMEOUT', 20),
    ],
];
