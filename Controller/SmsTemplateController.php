<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use MauticPlugin\N8nDispatchBundle\Service\SmsTemplateUsageFinder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Channels > SMS Templates (n8n). The screens are in AbstractTemplateController;
 * routes are mautic_n8ndispatch.smstemplate_index/_action (Config/config.php) and both views
 * live in Resources/views/SmsTemplate.
 */
class SmsTemplateController extends AbstractTemplateController
{
    // The constructor of the core controller changes between Mautic versions (Mautic 7 dropped MauticFactory), so it is
    // left to autowiring and the extra dependency comes in through a setter.
    #[Required]
    public function setUsageFinder(SmsTemplateUsageFinder $usageFinder): void
    {
        $this->usageFinder = $usageFinder;
    }

    protected function getTemplateBase(): string
    {
        return '@N8nDispatch/SmsTemplate';
    }

    protected function getModelName(): string
    {
        return 'n8ndispatch.smstemplate';
    }
}
