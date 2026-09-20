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
    ],
];
