<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Model;

use Mautic\CoreBundle\Model\FormModel;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\HsmTemplateRepository;
use MauticPlugin\N8nDispatchBundle\Form\Type\HsmTemplateType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Backs Controller/HsmTemplateController.php (Mautic's standard CRUD
 * flow, which looks this model up as 'n8ndispatch.hsmtemplate' — see the
 * service alias in Config/services.php) and the HSM Campaign Action's
 * dispatch-time template lookup.
 *
 * Reuses core's SMS permissions ('sms:smses') instead of registering a
 * permission set of its own — same choice Model/SmsTemplateModel.php
 * made, extended here: Mautic core has no WhatsApp/HSM permission set to
 * reuse, and this plugin is not registering one just for this screen.
 *
 * @extends FormModel<HsmTemplate>
 */
class HsmTemplateModel extends FormModel
{
    public function getRepository(): HsmTemplateRepository
    {
        return $this->em->getRepository(HsmTemplate::class);
    }

    public function getPermissionBase(): string
    {
        return 'sms:smses';
    }

    public function createForm($entity, $formFactory, $action = null, $options = []): FormInterface
    {
        if (!$entity instanceof HsmTemplate) {
            throw new MethodNotAllowedHttpException(['HsmTemplate']);
        }

        if (!empty($action)) {
            $options['action'] = $action;
        }

        return $formFactory->create(HsmTemplateType::class, $entity, $options);
    }

    public function getEntity($id = null): ?HsmTemplate
    {
        if (null === $id) {
            return new HsmTemplate();
        }

        return parent::getEntity($id);
    }
}
