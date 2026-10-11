<?php

return [
    [
        'key'   => 'whatsapp',
        'name'  => 'whatsapp::app.acl.whatsapp',
        'route' => [
            'admin.whatsapp.inbox.index',
            'admin.whatsapp.inbox.conversations',
            'admin.whatsapp.inbox.messages',
            'admin.whatsapp.inbox.messages.media',
            'admin.whatsapp.inbox.read',
            'admin.whatsapp.inbox.lead.search',
        ],
        'sort'  => 3,
    ], [
        'key'   => 'whatsapp.manage',
        'name'  => 'whatsapp::app.acl.manage',
        'route' => [
            'admin.whatsapp.inbox.claim',
            'admin.whatsapp.inbox.status',
            'admin.whatsapp.inbox.lead.link',
        ],
        'sort'  => 1,
    ], [
        'key'   => 'whatsapp.connections',
        'name'  => 'whatsapp::app.acl.connections',
        'route' => [
            'admin.whatsapp.connections.index',
            'admin.whatsapp.connections.list',
            'admin.whatsapp.connections.qr',
            'admin.whatsapp.connections.code',
            'admin.whatsapp.connections.action',
        ],
        'sort'  => 3,
    ], [
        'key'   => 'whatsapp.send',
        'name'  => 'whatsapp::app.acl.send',
        'route' => [
            'admin.whatsapp.inbox.send',
            'admin.whatsapp.inbox.retry',
        ],
        'sort'  => 2,
    ],
];
