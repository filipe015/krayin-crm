<?php

/**
 * The permission "automations" lives under Configurações → Automação, so the menu entry (whose key
 * is checked against the ACL) sits there too.
 */
return [
    [
        'key' => 'settings.automation.automations',
        'name' => 'automations::app.acl.view',
        'route' => ['admin.automations.index', 'admin.automations.runs'],
        'sort' => 6,
    ], [
        'key' => 'settings.automation.automations.create',
        'name' => 'automations::app.acl.create',
        'route' => ['admin.automations.store'],
        'sort' => 1,
    ], [
        'key' => 'settings.automation.automations.edit',
        'name' => 'automations::app.acl.edit',
        'route' => ['admin.automations.update', 'admin.automations.status', 'admin.automations.simulate'],
        'sort' => 2,
    ], [
        'key' => 'settings.automation.automations.delete',
        'name' => 'automations::app.acl.delete',
        'route' => ['admin.automations.delete'],
        'sort' => 3,
    ],
];
