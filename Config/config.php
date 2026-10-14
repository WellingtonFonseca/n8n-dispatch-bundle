<?php

declare(strict_types=1);

use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;

$n8nConfig = [
    'name'        => 'N8n Dispatch',
    'description' => 'Syncs Email templates to Mirror on save, and (later) dispatches Email/SMS/HSM sends via n8n',
    'version'     => '0.10.0',
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
            'mautic_n8ndispatch.hsmtemplate_index' => [
                'path'       => '/n8ndispatch/hsm-templates/{page}',
                'controller' => 'MauticPlugin\\N8nDispatchBundle\\Controller\\HsmTemplateController::indexAction',
            ],
            'mautic_n8ndispatch.hsmtemplate_action' => [
                'path'       => '/n8ndispatch/hsm-templates/{objectAction}/{objectId}',
                'controller' => 'MauticPlugin\\N8nDispatchBundle\\Controller\\HsmTemplateController::executeAction',
            ],
            'mautic_n8ndispatch.dispatch_index' => [
                'path'         => '/n8ndispatch/dispatches/{page}',
                'defaults'     => ['page' => 1],
                'requirements' => ['page' => '\\d+'],
                'controller'   => 'MauticPlugin\\N8nDispatchBundle\\Controller\\DispatchController::indexAction',
            ],
            'mautic_n8ndispatch.dispatch_details' => [
                'path'         => '/n8ndispatch/dispatches/details/{id}',
                'requirements' => ['id' => '\\d+'],
                'controller'   => 'MauticPlugin\\N8nDispatchBundle\\Controller\\DispatchController::detailsAction',
            ],
        ],
    ],

    'menu' => [
        'main' => [
            'mautic.n8ndispatch.menu.root' => [
                'id'        => 'mautic_n8ndispatch_root',
                'iconClass' => 'ri-send-plane-fill',
                'access'    => 'campaign:campaigns:full',
                'priority'  => 35,
            ],
            'mautic.n8ndispatch.dispatch.menu.index' => [
                'route'    => 'mautic_n8ndispatch.dispatch_index',
                'access'   => 'campaign:campaigns:full',
                'parent'   => 'mautic.n8ndispatch.menu.root',
                'priority' => 10,
            ],
            'mautic.n8ndispatch.smstemplate.menu.index' => [
                'route'    => 'mautic_n8ndispatch.smstemplate_index',
                'access'   => 'sms:smses:viewown',
                'parent'   => 'mautic.core.channels',
                'priority' => 5,
            ],
            'mautic.n8ndispatch.hsmtemplate.menu.index' => [
                'route'    => 'mautic_n8ndispatch.hsmtemplate_index',
                'access'   => 'sms:smses:viewown',
                'parent'   => 'mautic.core.channels',
                'priority' => 4,
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

// Mautic 7's AbstractIntegration no longer takes the session and takes FieldsWithUniqueIdentifier as the last argument.
if (class_exists('Mautic\\CoreBundle\\ProcessSignal\\ProcessSignalService')) {
    $integrationArguments = &$n8nConfig['services']['integrations']['mautic.integration.n8ndispatch']['arguments'];
    $integrationArguments = array_values(array_diff($integrationArguments, ['session']));
    $integrationArguments[] = 'mautic.lead.field.fields_with_unique_identifier';
    unset($integrationArguments);
}

return $n8nConfig;
