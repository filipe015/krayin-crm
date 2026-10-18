<?php

return [
    [
        'key'        => 'goals',
        'name'       => 'goals::app.menu.tracking',
        'route'      => 'admin.goals.index',
        'sort'       => 5,
        'icon-class' => 'icon-stats-up',
    ],
    [
        'key'        => 'settings.other_settings.goals',
        'name'       => 'goals::app.menu.settings',
        'info'       => 'goals::app.menu.settings_info',
        'route'      => 'admin.goals.settings.index',
        'sort'       => 7,
        'icon-class' => 'icon-leads',
    ],
];
