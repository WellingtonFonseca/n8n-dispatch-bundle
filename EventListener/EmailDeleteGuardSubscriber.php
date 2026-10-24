<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\EventListener;

use Mautic\ApiBundle\Helper\BatchIdToEntityHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Service\FlashBag;
use MauticPlugin\N8nDispatchBundle\Service\EmailTemplateUsageFinder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * An Email that a "Send via n8n (Email)" campaign step still points at is not
 * deleted, as SMS and HSM templates (Controller/SmsTemplateController.php,
 * HsmTemplateController.php). The Email is a core entity and core's delete
 * has no event that can veto it (EMAIL_PRE_DELETE only lets a listener throw),
 * so this looks at the core action about to run, whichever route led to it:
 *
 * - screen, one Email: the request is turned into a GET, which core's
 *   deleteAction answers with its own redirect to the list and deletes nothing
 *   ("else don't do anything"); an error flash says why;
 * - screen, batch: the Emails in use are dropped from the ids (one error each)
 *   and core deletes the rest;
 * - REST API: a 409 with the error, nothing deleted; for a batch, one Email in
 *   use refuses the whole call so no client reads a partial result as success.
 *
 * Only a user who may delete Emails gets these answers (anyone else is left to
 * core's own access denied, so the guard tells nobody which Emails are used).
 * Like the SMS/HSM messages, it names neither the Email nor the campaigns:
 * the Email's Campaigns tab (EmailCampaignsTabSubscriber) lists them.
 */
class EmailDeleteGuardSubscriber implements EventSubscriberInterface
{
    private const SCREEN_DELETE = 'Mautic\EmailBundle\Controller\EmailController::deleteAction';
    private const SCREEN_BATCH  = 'Mautic\EmailBundle\Controller\EmailController::batchDeleteAction';
    private const API_DELETE    = 'Mautic\EmailBundle\Controller\Api\EmailApiController::deleteEntityAction';
    private const API_BATCH     = 'Mautic\EmailBundle\Controller\Api\EmailApiController::deleteEntitiesAction';

    private const MESSAGE = 'mautic.n8ndispatch.email.error.in_use';

    public function __construct(
        private EmailTemplateUsageFinder $usageFinder,
        private FlashBag $flashBag,
        private TranslatorInterface $translator,
        private CorePermissions $security,
    ) {
    }

    /**
     * @return mixed[]
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::CONTROLLER => 'guard',
        ];
    }

    public function guard(ControllerEvent $event): void
    {
        $request = $event->getRequest();

        if (!in_array($request->attributes->get('_controller'), [self::SCREEN_DELETE, self::SCREEN_BATCH, self::API_DELETE, self::API_BATCH], true)
            || !$this->security->isGranted(['email:emails:deleteown', 'email:emails:deleteother'], 'MATCH_ONE')) {
            return;
        }

        switch ($request->attributes->get('_controller')) {
            case self::SCREEN_DELETE:
                $this->guardScreenDelete($request);
                break;
            case self::SCREEN_BATCH:
                $this->guardScreenBatch($request);
                break;
            case self::API_DELETE:
                if ([] !== $this->inUse([$request->attributes->get('id')])) {
                    $event->setController(fn (): JsonResponse => $this->refusal());
                }
                break;
            case self::API_BATCH:
                if ([] !== $this->inUse((new BatchIdToEntityHelper($request->query->all()))->getIds())) {
                    $event->setController(fn (): JsonResponse => $this->refusal());
                }
                break;
        }
    }

    private function guardScreenDelete(Request $request): void
    {
        if (Request::METHOD_POST !== $request->getMethod() || [] === $this->inUse([$request->attributes->get('objectId')])) {
            return;
        }

        $request->setMethod(Request::METHOD_GET);
        $this->flashBag->add(self::MESSAGE, [], FlashBag::LEVEL_ERROR);
    }

    private function guardScreenBatch(Request $request): void
    {
        if (Request::METHOD_POST !== $request->getMethod()) {
            return;
        }

        $ids = json_decode((string) $request->query->get('ids', '[]'), true);

        if (!is_array($ids)) {
            return;
        }

        $use = $this->inUse($ids);

        if ([] !== $use) {
            foreach ($use as $ignored) {
                $this->flashBag->add(self::MESSAGE, [], FlashBag::LEVEL_ERROR);
            }

            $request->query->set('ids', json_encode(array_values(array_filter($ids, fn ($id): bool => !is_numeric($id) || !in_array((int) $id, $use, true)))));
        }
    }

    /**
     * @param array<mixed> $ids
     *
     * @return list<int> the ids (numbers only) that a step still uses
     */
    private function inUse(array $ids): array
    {
        return $this->usageFinder->inUseAmong(array_map('intval', array_filter($ids, 'is_numeric')));
    }

    private function refusal(): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['code' => 409, 'message' => $this->translator->trans(self::MESSAGE, [], 'flashes'), 'type' => null]]],
            409
        );
    }
}
