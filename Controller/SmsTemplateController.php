<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CoreBundle\Controller\AbstractStandardFormController;
use MauticPlugin\N8nDispatchBundle\Service\SmsTemplateUsageFinder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Channels > SMS Templates (n8n). Plain Mautic standard CRUD, same shape
 * as core's PointBundle GroupController: routes are
 * mautic_n8ndispatch.smstemplate_index/_action (Config/config.php) and
 * both views live in Resources/views/SmsTemplate.
 *
 * Two additions on top of the standard flow, both backed by
 * SmsTemplateUsageFinder (injected per action — Mautic's executeAction()
 * forwards to these methods, so Symfony autowires service arguments):
 * - the edit page lists the campaigns whose SMS steps use the template;
 * - a template still used by a campaign can't be deleted, since those
 *   steps would then fail on every send.
 */
class SmsTemplateController extends AbstractStandardFormController
{
    private ?SmsTemplateUsageFinder $usageFinder = null;

    protected function getTemplateBase(): string
    {
        return '@N8nDispatch/SmsTemplate';
    }

    protected function getModelName(): string
    {
        return 'n8ndispatch.smstemplate';
    }

    protected function getDefaultOrderColumn(): string
    {
        return 'name';
    }

    public function indexAction(Request $request, $page = 1): Response
    {
        return parent::indexStandard($request, $page);
    }

    /**
     * @return JsonResponse|Response
     */
    public function newAction(Request $request)
    {
        return parent::newStandard($request);
    }

    /**
     * @return JsonResponse|Response
     */
    public function editAction(Request $request, SmsTemplateUsageFinder $usageFinder, $objectId, $ignorePost = false)
    {
        $this->usageFinder = $usageFinder;

        return parent::editStandard($request, $objectId, $ignorePost);
    }

    /**
     * @return JsonResponse|RedirectResponse|Response
     */
    public function cloneAction(Request $request, $objectId)
    {
        return parent::cloneStandard($request, $objectId);
    }

    /**
     * @return JsonResponse|RedirectResponse
     */
    public function deleteAction(Request $request, SmsTemplateUsageFinder $usageFinder, $objectId)
    {
        $template = $this->getModel($this->getModelName())->getEntity($objectId);

        if ('POST' === $request->getMethod() && null !== $template && null !== $template->getId()) {
            $usages = $usageFinder->findUsages((int) $template->getId());

            if ([] !== $usages) {
                $page = $request->getSession()->get('mautic.'.$this->getSessionBase().'.page', 1);

                return $this->postActionRedirect([
                    'returnUrl'       => $this->generateUrl($this->getIndexRoute(), ['page' => $page]),
                    'viewParameters'  => ['page' => $page],
                    'contentTemplate' => static::class.'::indexAction',
                    'passthroughVars' => ['mauticContent' => $this->getJsLoadMethodPrefix()],
                    'flashes'         => [$this->inUseFlash()],
                ]);
            }
        }

        return parent::deleteStandard($request, $objectId);
    }

    /**
     * @return JsonResponse|RedirectResponse
     */
    public function batchDeleteAction(Request $request, SmsTemplateUsageFinder $usageFinder)
    {
        if ('POST' === $request->getMethod()) {
            $deletable = [];

            // Templates still in use are dropped from the batch (with an
            // error each); the rest go through the standard batch delete.
            foreach ((array) json_decode((string) $request->query->get('ids', ''), true) as $objectId) {
                if (!$usageFinder->isInUse((int) $objectId)) {
                    $deletable[] = $objectId;

                    continue;
                }

                $flash = $this->inUseFlash();
                $this->addFlashMessage($flash['msg'], [], $flash['type']);
            }

            $request->query->set('ids', json_encode($deletable));
        }

        return parent::batchDeleteStandard($request);
    }

    protected function getViewArguments(array $args, $action): array
    {
        if ('edit' === $action && null !== $this->usageFinder) {
            $template = $args['viewParameters']['entity'] ?? null;

            $args['viewParameters']['campaignUsages'] = null !== $template && null !== $template->getId()
                ? $this->usageFinder->findUsages((int) $template->getId())
                : [];
        }

        return $args;
    }

    /**
     * Deliberately names neither the template nor the campaigns: the edit
     * page's "Campaigns using this template" list is where to look.
     *
     * @return array{type: string, msg: string}
     */
    private function inUseFlash(): array
    {
        return [
            'type' => 'error',
            'msg'  => 'mautic.n8ndispatch.smstemplate.error.in_use',
        ];
    }
}
