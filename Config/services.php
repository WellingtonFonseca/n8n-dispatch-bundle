<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

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
    $excludes = [
        'Integration/N8nDispatchIntegration.php',
    ];

    $services->load('MauticPlugin\\N8nDispatchBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    // Entity/ is in DEFAULT_EXCLUDES; repositories still have to be services
    // (CommonRepository is a ServiceEntityRepository) — same as core bundles.
    $services->load('MauticPlugin\\N8nDispatchBundle\\Entity\\', '../Entity/*Repository.php')
        ->tag(Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\ServiceRepositoryCompilerPass::REPOSITORY_SERVICE_TAG);

    // Mautic's standard CRUD controller (Controller/SmsTemplateController.php,
    // Controller/HsmTemplateController.php) resolves its model by the
    // 'mautic.<bundle>.model.<name>' id convention.
    $services->alias('mautic.n8ndispatch.model.smstemplate', MauticPlugin\N8nDispatchBundle\Model\SmsTemplateModel::class);
    $services->alias('mautic.n8ndispatch.model.hsmtemplate', MauticPlugin\N8nDispatchBundle\Model\HsmTemplateModel::class);
};
