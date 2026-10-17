<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CoreBundle\Controller\AbstractStandardFormController;
use MauticPlugin\N8nDispatchBundle\Service\HsmTemplateUsageFinder;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableRows;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Channels > HSM Templates (n8n). Same shape as Controller/
 * SmsTemplateController.php in every respect — plain Mautic standard
 * CRUD, routes are mautic_n8ndispatch.hsmtemplate_index/_action
 * (Config/config.php), both views live in Resources/views/HsmTemplate.
 *
 * Two additions on top of the standard flow, both backed by
 * HsmTemplateUsageFinder:
 * - the edit page lists the campaigns whose HSM steps use the template;
 * - a template still used by a campaign can't be deleted, since those
 *   steps would then fail on every send.
 *
 * HsmTemplateUsageFinder is constructor-injected, not action-method-
 * injected — see SmsTemplateController's own docblock for exactly why
 * (AbstractStandardFormController::cloneStandard() calls editAction() as
 * a direct PHP call that bypasses Symfony's routing/autowiring; a typed
 * action-method parameter breaks on that specific call with a TypeError).
 */
class HsmTemplateController extends AbstractStandardFormController
{
    private HsmTemplateUsageFinder $usageFinder;

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
            'contentTemplate' => '@N8nDispatch/HsmTemplate/details.html.twig',
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
            'msg'  => 'mautic.n8ndispatch.hsmtemplate.error.in_use',
        ];
    }
}
