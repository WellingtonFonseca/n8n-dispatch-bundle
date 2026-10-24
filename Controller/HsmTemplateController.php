<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Translation\Translator;
use Mautic\FormBundle\Helper\FormFieldHelper;
use MauticPlugin\N8nDispatchBundle\Service\HsmTemplateUsageFinder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Channels > HSM Templates (n8n). The screens are in AbstractTemplateController;
 * routes are mautic_n8ndispatch.hsmtemplate_index/_action (Config/config.php) and both views
 * live in Resources/views/HsmTemplate.
 */
class HsmTemplateController extends AbstractTemplateController
{
    public function __construct(
        FormFactoryInterface $formFactory,
        FormFieldHelper $fieldHelper,
        ManagerRegistry $managerRegistry,
        MauticFactory $factory,
        ModelFactory $modelFactory,
        UserHelper $userHelper,
        CoreParametersHelper $coreParametersHelper,
        EventDispatcherInterface $dispatcher,
        Translator $translator,
        FlashBag $flashBag,
        RequestStack $requestStack,
        CorePermissions $security,
        HsmTemplateUsageFinder $usageFinder
    ) {
        parent::__construct($formFactory, $fieldHelper, $managerRegistry, $factory, $modelFactory, $userHelper, $coreParametersHelper, $dispatcher, $translator, $flashBag, $requestStack, $security, $usageFinder);
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
