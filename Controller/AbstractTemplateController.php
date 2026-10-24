<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CoreBundle\Controller\AbstractStandardFormController;
use MauticPlugin\N8nDispatchBundle\Service\AbstractTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableRows;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the SMS and HSM template screens (Channels > SMS / HSM Templates, n8n)
 * share: plain Mautic standard CRUD, same shape as core's PointBundle
 * GroupController, with two additions on top of the standard flow, both
 * backed by the channel's usage finder (Service/AbstractTemplateUsageFinder.php):
 * - the edit page lists the campaigns whose steps use the template;
 * - a template still used by a campaign can't be deleted, since those steps
 *   would then fail on every send (one at a time, and in a batch where the
 *   ones in use are dropped with an error each and the rest is deleted).
 *
 * A subclass names its views folder (getTemplateBase()) and its model
 * (getModelName(), which also gives the "in use" message key:
 * mautic.<model>.error.in_use). Both channels use the sms: permissions.
 *
 * The usage finder is injected by a setter of the subclass (#[Required], typed to its
 * own finder; Mautic 7's core constructor changed, so the controllers leave it to
 * autowiring), not action-method-injected:
 * AbstractStandardFormController::cloneStandard() calls
 * `$this->editAction($request, $newEntity, true)` as a direct PHP call, not
 * through Symfony's routing (that only happens for the normal edit/new HTTP
 * requests, via executeAction()'s forward()). A finder parameter on
 * editAction() worked for those, but broke that direct call — the cloned
 * entity landed in the $usageFinder slot instead, throwing a TypeError.
 * Keeping editAction()'s signature identical to core's (Request, $objectId,
 * $ignorePost) avoids that mismatch.
 */
abstract class AbstractTemplateController extends AbstractStandardFormController
{
    protected AbstractTemplateUsageFinder $usageFinder;

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
    public function editAction(Request $request, $objectId, $ignorePost = false)
    {
        return parent::editStandard($request, $objectId, $ignorePost);
    }

    /**
     * Read-only view of the template, the body of the modal opened (data-toggle="ajaxmodal") from the template's name on the
     * list. The same information as the edit page, with no form.
     */
    public function detailsAction(Request $request, $objectId): Response
    {
        $template = $this->getModel($this->getModelName())->getEntity($objectId);

        if (null === $template || null === $template->getId()) {
            return $this->notFound();
        }

        if (!$this->security->hasEntityAccess('sms:smses:viewown', 'sms:smses:viewother', $template->getCreatedBy())) {
            return $this->accessDenied();
        }

        return $this->delegateView([
            'viewParameters' => [
                'entity'         => $template,
                'variables'      => TemplateVariableRows::fromJson($template->getVariablesJson()),
                'campaignUsages' => $this->usageFinder->findUsages((int) $template->getId()),
                'canEdit'        => $this->security->hasEntityAccess('sms:smses:editown', 'sms:smses:editother', $template->getCreatedBy()),
            ],
            'contentTemplate' => $this->getTemplateBase().'/details.html.twig',
            'passthroughVars' => [
                'route' => false,
            ],
        ]);
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
    public function deleteAction(Request $request, $objectId)
    {
        $template = $this->getModel($this->getModelName())->getEntity($objectId);

        if ('POST' === $request->getMethod() && null !== $template && null !== $template->getId()) {
            $usages = $this->usageFinder->findUsages((int) $template->getId());

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
    public function batchDeleteAction(Request $request)
    {
        if ('POST' === $request->getMethod()) {
            $deletable = [];

            // Templates still in use are dropped from the batch (with an
            // error each); the rest go through the standard batch delete.
            foreach ((array) json_decode((string) $request->query->get('ids', ''), true) as $objectId) {
                if (!$this->usageFinder->isInUse((int) $objectId)) {
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
        if ('edit' === $action) {
            $template = $args['viewParameters']['entity'] ?? null;

            $args['viewParameters']['campaignUsages'] = null !== $template && null !== $template->getId()
                ? $this->usageFinder->findUsages((int) $template->getId())
                : [];

            // Same 'permissions' array shape as AbstractStandardFormController::
            // indexStandard() — the edit view's Clone/Delete options dropdown
            // (form.html.twig's 'actions' block) needs it the same way.
            $args['viewParameters']['permissions'] = $this->security->isGranted(
                [
                    $this->getPermissionBase().':create',
                    $this->getPermissionBase().':deleteown',
                    $this->getPermissionBase().':deleteother',
                ],
                'RETURN_ARRAY'
            );
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
            'msg'  => 'mautic.'.$this->getModelName().'.error.in_use',
        ];
    }
}
