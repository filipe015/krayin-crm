<?php

return [
    'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET'),

    // Show "typing…" to the customer while an operator writes in the Inbox.
    'send_typing' => env('WHATSAPP_SEND_TYPING', true),

    // Official WhatsApp Business Cloud API (Meta). One app serves every connected number.
    'cloud' => [
        'app_secret' => env('WHATSAPP_CLOUD_APP_SECRET'),
        'verify_token' => env('WHATSAPP_CLOUD_VERIFY_TOKEN'),
        'graph_version' => env('WHATSAPP_CLOUD_GRAPH_VERSION', 'v23.0'),

        // Embedded Signup ("Conectar com WhatsApp"): the Meta app and its signup configuration.
        // The app secret above is the same one that trades the signup code for the token.
        'app_id' => env('META_APP_ID'),
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
        'url' => env('WAHA_API_URL'),
        'api_key' => env('WAHA_API_KEY'),
        'hmac_secret' => env('WAHA_HMAC_SECRET'),

        // WAHA sessions this installation owns, comma separated (one WAHA server can serve
        // several CRMs). Empty = every session of the server, as before.
        'sessions' => env('WAHA_SESSIONS', ''),
    ],

    // Limits of the automatic follow-up API (/api/v1/conversations), per channel. The unofficial
    // channel (WAHA) has no 24h window or templates, so the protection against bans is a
    // lower volume: fewer follow-ups, further apart, and a daily cap per WhatsApp session.
    'api_followup' => [
        'cloud' => [
            'max' => (int) env('WHATSAPP_FOLLOWUP_CLOUD_MAX', 2),
            'gap_hours' => (int) env('WHATSAPP_FOLLOWUP_CLOUD_GAP_HOURS', 24),
        ],
        'waha' => [
            'max' => (int) env('WHATSAPP_FOLLOWUP_WAHA_MAX', 3),
            'gap_hours' => (int) env('WHATSAPP_FOLLOWUP_WAHA_GAP_HOURS', 48),
            // Follow-ups per WAHA session in the last 24 hours; 0 = no cap.
            'daily_cap' => (int) env('WHATSAPP_FOLLOWUP_WAHA_DAILY_CAP', 30),
        ],
    ],

    // Lead opened automatically when a contact writes in a conversation without a lead.
    // off = never (default), ads = only conversations that came from a click-to-WhatsApp ad,
    // all = every new contact.
    'auto_lead' => [
        'mode' => env('WHATSAPP_AUTO_LEAD', 'off'),
        'pipeline_id' => env('WHATSAPP_AUTO_LEAD_PIPELINE_ID'),
        // Stage by id, or by name inside the pipeline (the ids differ between installations).
        'stage_id' => env('WHATSAPP_AUTO_LEAD_STAGE_ID'),
        'stage' => env('WHATSAPP_AUTO_LEAD_STAGE'),
        // Lead source name; empty = "WhatsApp Anúncio" for ad conversations, "WhatsApp" otherwise.
        'source' => env('WHATSAPP_AUTO_LEAD_SOURCE'),
        // Tag names, comma separated (created when missing).
        'tags' => env('WHATSAPP_AUTO_LEAD_TAGS', ''),
        // Owner when the conversation has no assigned operator; empty = no owner.
        'user_id' => env('WHATSAPP_AUTO_LEAD_USER_ID'),
    ],

    // WhatsApp notice to the team (a number or a group) on a new automatic lead and on new
    // messages from conversations that already have a lead. Always sent through WAHA, also
    // when the conversation came through the Cloud API (no 24h window). Off when `to` or
    // `session` is empty.
    'notify' => [
        // Destinations, comma separated: digits (55 is added when missing) or a full chatId
        // (…@c.us, …@g.us for a group).
        'to' => env('WHATSAPP_NOTIFY_TO', ''),
        // WAHA session that sends the notices. Its own events never become conversations.
        'session' => env('WHATSAPP_NOTIFY_SESSION', ''),
        // new_lead, new_message (comma separated); empty = nothing is sent.
        'events' => env('WHATSAPP_NOTIFY_EVENTS', ''),
        // At most one new_message notice per conversation in this many minutes (0 = no limit).
        'cooldown_minutes' => env('WHATSAPP_NOTIFY_COOLDOWN_MINUTES', 30),
        // Optional: another WAHA server for the notices; empty = WAHA_API_URL / WAHA_API_KEY.
        'waha_url' => env('WHATSAPP_NOTIFY_WAHA_URL'),
        'waha_api_key' => env('WHATSAPP_NOTIFY_WAHA_API_KEY'),
    ],
];
