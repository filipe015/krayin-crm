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

        // Embedded Signup ("Conectar com WhatsApp"): the Meta app and its signup configuration.
        // The app secret above is the same one that trades the signup code for the token.
        'app_id'       => env('META_APP_ID'),
        'es_config_id' => env('META_ES_CONFIG_ID'),

        // Default of the "my number is already on the WhatsApp Business app" option (coexistence).
        'es_coexistence' => env('META_ES_COEXISTENCE', true),

        // Ask Meta to send each connected account's webhooks to this installation's URL
        // (several installations sharing one Meta app). Off = use the URL saved in the app.
        'webhook_override' => env('WHATSAPP_CLOUD_WEBHOOK_OVERRIDE', true),
    ],

    // Key the super-admin panel sends (X-Control-Key) to read health and usage. At least 24
    // characters; empty keeps the endpoint off.
    'control_key' => env('WHATSAPP_CONTROL_KEY'),

    'app_version' => env('APP_VERSION'),

    // Optional path to ffmpeg; empty means "look it up on the PATH". Used to send voice notes
    // recorded in the browser through the Cloud API.
    'ffmpeg' => env('WHATSAPP_FFMPEG_BINARY'),

    'waha' => [
        'url'         => env('WAHA_API_URL'),
        'api_key'     => env('WAHA_API_KEY'),
        'hmac_secret' => env('WAHA_HMAC_SECRET'),
    ],
];
