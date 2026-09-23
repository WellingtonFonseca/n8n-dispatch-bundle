<?php

declare(strict_types=1);

use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;

return [
    'name'        => 'N8n Dispatch',
    'description' => 'Syncs Email templates to Mirror on save, and (later) dispatches Email/SMS/HSM sends via n8n',
    'version'     => '0.2.0',
    'author'      => 'Wellington Fonseca',

    'routes' => [
        'main' => [
            'mautic_n8ndispatch.smstemplate_index' => [
                'path'       => '/n8ndispatch/sms-templates/{page}',
                'controller' => 'MauticPlugin\\N8nDispatchBundle\\Controller\\SmsTemplateController::indexAction',
            ],
            'mautic_n8ndispatch.smstemplate_action' => [
                'path'       => '/n8ndispatch/sms-templates/{objectAction}/{objectId}',
                'controller' => 'MauticPlugin\\N8nDispatchBundle\\Controller\\SmsTemplateController::executeAction',
            ],
        ],
    ],

    'menu' => [
        'main' => [
            'mautic.n8ndispatch.smstemplate.menu.index' => [
                'route'    => 'mautic_n8ndispatch.smstemplate_index',
                'access'   => 'sms:smses:viewown',
                'parent'   => 'mautic.core.channels',
                'priority' => 5,
            ],
        ],
    ],

    'services' => [
        'integrations' => [
            'mautic.integration.n8ndispatch' => [
                'class'     => N8nDispatchIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'session',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                ],
            ],
        ],
    ],
];
