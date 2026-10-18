<?php

return [
    [
        'key'   => 'goals',
        'name'  => 'goals::app.acl.tracking',
        'route' => ['admin.goals.index', 'admin.goals.data'],
        'sort'  => 5,
    ],
    [
        'key'   => 'settings.other_settings.goals',
        'name'  => 'goals::app.acl.settings',
        'route' => ['admin.goals.settings.index', 'admin.goals.settings.data', 'admin.goals.settings.history'],
        'sort'  => 8,
    ], [
        'key'   => 'settings.other_settings.goals.edit',
        'name'  => 'goals::app.acl.settings_edit',
        'route' => [
            'admin.goals.settings.preview',
            'admin.goals.settings.plan',
            'admin.goals.settings.plan.delete',
            'admin.goals.settings.copy',
            'admin.goals.settings.working_days',
            'admin.goals.settings.save',
        ],
        'sort'  => 1,
    ],
];
