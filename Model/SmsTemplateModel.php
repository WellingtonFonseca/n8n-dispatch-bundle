<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Model;

use Mautic\CoreBundle\Model\FormModel;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplate;
use MauticPlugin\N8nDispatchBundle\Entity\SmsTemplateRepository;
use MauticPlugin\N8nDispatchBundle\Form\Type\SmsTemplateType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * Backs Controller/SmsTemplateController.php (Mautic's standard CRUD
 * flow, which looks this model up as 'n8ndispatch.smstemplate' — see the
 * service alias in Config/services.php) and the SMS Campaign Action's
 * dispatch-time template lookup.
 *
 * Reuses core's SMS permissions ('sms:smses') instead of registering a
 * permission set of its own: whoever may manage Text Messages may manage
 * these too.
 *
 * @extends FormModel<SmsTemplate>
 */
class SmsTemplateModel extends FormModel
{
    public function getRepository(): SmsTemplateRepository
    {
        return $this->em->getRepository(SmsTemplate::class);
    }

    public function getPermissionBase(): string
    {
        return 'sms:smses';
    }

    public function createForm($entity, $formFactory, $action = null, $options = []): FormInterface
    {
        if (!$entity instanceof SmsTemplate) {
            throw new MethodNotAllowedHttpException(['SmsTemplate']);
        }

        if (!empty($action)) {
            $options['action'] = $action;
        }

        return $formFactory->create(SmsTemplateType::class, $entity, $options);
    }

    public function getEntity($id = null): ?SmsTemplate
    {
        if (null === $id) {
            return new SmsTemplate();
        }

        return parent::getEntity($id);
    }
}
