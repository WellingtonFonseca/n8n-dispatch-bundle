<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use MauticPlugin\N8nDispatchBundle\Service\HsmTemplateUsageFinder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Channels > HSM Templates (n8n). The screens are in AbstractTemplateController;
 * routes are mautic_n8ndispatch.hsmtemplate_index/_action (Config/config.php) and both views
 * live in Resources/views/HsmTemplate.
 */
class HsmTemplateController extends AbstractTemplateController
{
    // The constructor of the core controller changes between Mautic versions (Mautic 7 dropped MauticFactory), so it is
    // left to autowiring and the extra dependency comes in through a setter.
    #[Required]
    public function setUsageFinder(HsmTemplateUsageFinder $usageFinder): void
    {
        $this->usageFinder = $usageFinder;
    }

    protected function getTemplateBase(): string
    {
        return '@N8nDispatch/HsmTemplate';
    }

    protected function getModelName(): string
    {
        return 'n8ndispatch.hsmtemplate';
    }
}
