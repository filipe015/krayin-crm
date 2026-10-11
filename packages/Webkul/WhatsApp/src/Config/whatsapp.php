<?php

return [
    'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET'),

    // Show "typing…" to the customer while an operator writes in the Inbox.
    'send_typing' => env('WHATSAPP_SEND_TYPING', true),

    // Official WhatsApp Business Cloud API (Meta). One app serves every connected number.
    'cloud' => [
        'app_secret'    => env('WHATSAPP_CLOUD_APP_SECRET'),
        'verify_token'  => env('WHATSAPP_CLOUD_VERIFY_TOKEN'),
        'graph_version' => env('WHATSAPP_CLOUD_GRAPH_VERSION', 'v23.0'),
    ],

    'waha' => [
        'url'         => env('WAHA_API_URL'),
        'api_key'     => env('WAHA_API_KEY'),
        'hmac_secret' => env('WAHA_HMAC_SECRET'),
    ],
];
