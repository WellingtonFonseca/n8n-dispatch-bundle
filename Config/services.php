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
};
