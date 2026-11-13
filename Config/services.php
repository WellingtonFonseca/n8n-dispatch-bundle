<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    // Integration/N8nDispatchIntegration.php is excluded here: its constructor takes a fixed,
    // untyped-by-convention list of core service ids (same pattern every Mautic Integration
    // uses, e.g. MauticClearbitBundle), so it's registered explicitly in Config/config.php's
    // services.integrations block instead of relying on autowiring to guess the right services.
    // Service/Provider/Dialog/ names the DialogHSM plugin's classes: it is registered below only where they exist.
    $excludes = [
        'Integration/N8nDispatchIntegration.php',
        'Service/Provider/Dialog',
        // Values made with `new` at the moment of a send, not services: their constructors take plain values.
        'Service/Provider/HsmSendRequest.php',
        'Service/Provider/HsmSendResult.php',
    ];

    $services->load('MauticPlugin\\N8nDispatchBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    // Entity/ is in DEFAULT_EXCLUDES; repositories still have to be services
    // (CommonRepository is a ServiceEntityRepository) — same as core bundles.
    $services->load('MauticPlugin\\N8nDispatchBundle\\Entity\\', '../Entity/*Repository.php')
        ->tag(Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);

    // A plain string argument cannot be autowired.
    $services->set(MauticPlugin\N8nDispatchBundle\Service\BackgroundCommandLauncher::class)
        ->args(['%kernel.project_dir%', null]);

    // One pacer per class: it remembers when the last call started, and the status poll and the Mirror resync must not
    // count each other's calls.
    $services->set(MauticPlugin\N8nDispatchBundle\Service\CallPacer::class)->share(false);

    // Core registers its cache helper only under this id, so autowiring by class finds nothing.
    $services->set(MauticPlugin\N8nDispatchBundle\Service\Alert\AlertThrottle::class)
        ->args([service('mautic.helper.cache_storage')]);

    // Doctrine does not pick up an EventSubscriber by autoconfiguration here (core tags its own the same way).
    $services->get(MauticPlugin\N8nDispatchBundle\EventListener\TemplateRenameSubscriber::class)
        ->tag('doctrine.event_subscriber');

    // The DialogHSM plugin is optional. Where its classes exist the gateway is the real one; anywhere else it is the
    // null gateway and nothing of Service/Provider/Dialog/ is read or autoloaded. The class name is a string on purpose:
    // ::class would not autoload either, but a string cannot be mistaken for a use of the class.
    $gateway = class_exists('MauticPlugin\\DialogHSMBundle\\Integration\\DialogHSMIntegration')
        ? MauticPlugin\N8nDispatchBundle\Service\Provider\Dialog\DialogHsmGateway::class
        : MauticPlugin\N8nDispatchBundle\Service\Provider\NullHsmProviderGateway::class;

    $services->set($gateway);
    $services->alias(MauticPlugin\N8nDispatchBundle\Service\Provider\HsmProviderGateway::class, $gateway);

    // Mautic's standard CRUD controller (Controller/SmsTemplateController.php,
    // Controller/HsmTemplateController.php) resolves its model by the
    // 'mautic.<bundle>.model.<name>' id convention.
    $services->alias('mautic.n8ndispatch.model.smstemplate', MauticPlugin\N8nDispatchBundle\Model\SmsTemplateModel::class);
    $services->alias('mautic.n8ndispatch.model.hsmtemplate', MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel::class);
};
