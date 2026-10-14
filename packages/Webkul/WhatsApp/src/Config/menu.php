<?php

return [
    [
        'key'        => 'whatsapp',
        'name'       => 'whatsapp::app.menu.inbox',
        'route'      => 'admin.whatsapp.inbox.index',
        'sort'       => 3,
        'icon-class' => 'icon-message',
    ],
    [
        'key'        => 'agenda',
        'name'       => 'whatsapp::app.menu.agenda',
        'route'      => 'admin.agenda.index',
        'sort'       => 4,
        'icon-class' => 'icon-calendar',
    ],
    [
        'key'        => 'ads',
        'name'       => 'whatsapp::app.menu.ads',
        'route'      => 'admin.ads.index',
        'sort'       => 4,
        'icon-class' => 'icon-leads',
    ],
    [
        'key'        => 'settings.other_settings.agenda',
        'name'       => 'whatsapp::app.menu.agenda_settings',
        'info'       => 'whatsapp::app.menu.agenda_settings_info',
        'route'      => 'admin.agenda.settings.index',
        'sort'       => 5,
        'icon-class' => 'icon-calendar',
    ],
    [
        'key'        => 'settings.other_settings.api',
        'name'       => 'whatsapp::app.menu.api_settings',
        'info'       => 'whatsapp::app.menu.api_settings_info',
        'route'      => 'admin.api.settings.index',
        'sort'       => 6,
        'icon-class' => 'icon-settings-webhooks',
    ],
];
