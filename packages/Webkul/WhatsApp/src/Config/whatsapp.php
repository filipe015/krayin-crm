<?php

return [
    'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET'),

    'waha' => [
        'url'         => env('WAHA_API_URL'),
        'api_key'     => env('WAHA_API_KEY'),
        'hmac_secret' => env('WAHA_HMAC_SECRET'),
    ],
];
