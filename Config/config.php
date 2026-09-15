<?php

declare(strict_types=1);

use MauticPlugin\N8nDispatchBundle\Integration\N8nDispatchIntegration;

return [
    'name'        => 'N8n Dispatch',
    'description' => 'Syncs Email templates to Mirror on save, and (later) dispatches Email/SMS/HSM sends via n8n',
    'version'     => '0.1.0',
    'author'      => 'Wellington Fonseca',

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
