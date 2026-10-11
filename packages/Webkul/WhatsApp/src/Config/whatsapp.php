<?php

return [
    'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET'),

    // Show "typing…" to the customer while an operator writes in the Inbox.
    'send_typing' => env('WHATSAPP_SEND_TYPING', true),

    'waha' => [
        'url'         => env('WAHA_API_URL'),
        'api_key'     => env('WAHA_API_KEY'),
        'hmac_secret' => env('WAHA_HMAC_SECRET'),
    ],
];
