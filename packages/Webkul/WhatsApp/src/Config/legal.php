<?php

/*
 * Public legal pages (privacy, terms, data deletion) required by the Meta App Review.
 * Every value can be changed per instance through the .env; the defaults are Teway's.
 */
return [
    'app_name' => env('LEGAL_APP_NAME', 'Teway CRM'),
    'trade_name' => env('LEGAL_TRADE_NAME', 'Teway Marketing'),
    'company_name' => env('LEGAL_COMPANY_NAME', '42.534.224 Filipe Lemes do Carmo Costa'),
    'cnpj' => env('LEGAL_CNPJ', '42.534.224/0001-23'),
    'address' => env('LEGAL_ADDRESS', 'QS 5, Rua 800 B, Brasília/DF, CEP 71955-000'),
    'email' => env('LEGAL_EMAIL', 'comercial@telwaymarketing.com.br'),
    'website' => env('LEGAL_WEBSITE', 'https://telwaymarketing.com.br'),
    'forum' => env('LEGAL_FORUM', 'Brasília/DF'),
    'updated_at' => env('LEGAL_UPDATED_AT', '13/11/2026'),
    'updated_at_en' => env('LEGAL_UPDATED_AT_EN', 'November 13, 2026'),

    // Days to answer a deletion request.
    'deletion_days' => (int) env('LEGAL_DELETION_DAYS', 30),

    // Secret that signs Meta's data deletion callback. WHATSAPP_CLOUD_APP_SECRET is used first;
    // META_APP_SECRET covers an instance whose Meta app is not the WhatsApp Cloud one.
    'meta_app_secret' => env('META_APP_SECRET'),
];
