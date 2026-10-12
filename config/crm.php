<?php

/**
 * Features this installation does not offer.
 *
 * Each entry is an ACL key (see packages/Webkul/Admin/src/Config/acl.php) and also covers its
 * children: "settings.inventory" turns off warehouses too. A disabled feature disappears from the
 * menu and from every screen that checks its permission, for administrators as well, and its pages
 * answer 404.
 *
 * Override per installation with CRM_DISABLED_FEATURES (comma separated; empty = everything on).
 */
return [
    'disabled_features' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CRM_DISABLED_FEATURES',
            'quotes,mail,help,settings.inventory,settings.automation.events,settings.automation.campaigns,settings.automation.email_templates,settings.lead.types,settings.other_settings.web_forms,settings.user.groups,settings.automation.workflows'
        ))
    ))),

    /**
     * Route names (wildcards allowed) that belong to each feature. They back up the ACL mapping,
     * which has gaps upstream (for instance the warehouses ACL names a route that does not exist).
     */
    'feature_routes' => [
        'quotes'                               => ['admin.quotes.*'],
        'mail'                                 => ['admin.mail.*'],
        'help'                                 => ['admin.help.*'],
        'settings.inventory'                   => ['admin.settings.warehouse.*', 'admin.settings.warehouses.*'],
        'settings.automation.events'           => ['admin.settings.marketing.events.*'],
        'settings.automation.campaigns'        => ['admin.settings.marketing.campaigns.*'],
        'settings.automation.email_templates'  => ['admin.settings.email_templates.*'],
        'settings.lead.types'                  => ['admin.settings.types.*'],
        'settings.other_settings.web_forms'    => ['admin.settings.web_forms.*'],
        'settings.user.groups'                 => ['admin.settings.groups.*'],
        'settings.automation.workflows'        => ['admin.settings.workflows.*'],
    ],
];
