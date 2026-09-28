<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CampaignBundle\Model\EventLogModel;
use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\N8nDispatchBundle\UnsubscribeVariable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backs the campaign builder's "Send via n8n (Email)" action form. The
 * generic /ajax route (Mautic\CoreBundle\Controller\AjaxController::
 * delegateAjaxAction) resolves action=plugin:N8nDispatch:getEmailVariables
 * to getEmailVariablesAction() below, by bundle-name convention — no
 * route registration needed.
 */
class AjaxController extends CommonAjaxController
{
    private const VARIABLE_PATTERN = '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/';

    public function getEmailVariablesAction(
        Request $request,
        EmailModel $emailModel,
        FieldModel $fieldModel,
        CustomObjectModel $customObjectModel,
    ): JsonResponse {
        $emailId = (int) $request->request->get('emailId', $request->query->get('emailId', 0));

        // Contact fields and Custom Objects are returned on every call, not
        // just when a valid email is picked — the JS needs them ready
        // before the user has necessarily chosen a template, and neither
        // depends on it.
        $fields        = $fieldModel->getFieldList(false);
        $customObjects = $this->buildCustomObjectsList($customObjectModel);

        if ($emailId <= 0) {
            return $this->sendJsonResponse(['success' => 1, 'variables' => [], 'fields' => $fields, 'customObjects' => $customObjects]);
        }

        $email = $emailModel->getEntity($emailId);

        if (null === $email) {
            return $this->sendJsonResponse(['success' => 0, 'variables' => [], 'fields' => $fields, 'customObjects' => $customObjects]);
        }

        $variables = $this->extractMappableVariables((string) $email->getCustomHtml());

        return $this->sendJsonResponse(['success' => 1, 'variables' => $variables, 'fields' => $fields, 'customObjects' => $customObjects]);
    }

    /**
     * Backs the SMS Template form (Form/Type/SmsTemplateType.php). Same response shape as
     * getEmailVariablesAction() (so the campaign builder's JS can reuse the
     * exact same rendering code for both), but there's no entity to load —
     * the {{variable}} names are extracted straight from the pasted SMS
     * text the user already has in the browser, sent back as 'text'.
     */
    public function getSmsVariablesAction(
        Request $request,
        FieldModel $fieldModel,
        CustomObjectModel $customObjectModel,
    ): JsonResponse {
        $text = (string) $request->request->get('text', $request->query->get('text', ''));

        $fields        = $fieldModel->getFieldList(false);
        $customObjects = $this->buildCustomObjectsList($customObjectModel);
        $variables     = $this->extractMappableVariables($text);

        return $this->sendJsonResponse(['success' => 1, 'variables' => $variables, 'fields' => $fields, 'customObjects' => $customObjects]);
    }

    /**
     * Backs the HSM Template form (Form/Type/HsmTemplateType.php). Same
     * shape as getSmsVariablesAction() in every respect — see that one's
     * own docblock.
     */
    public function getHsmVariablesAction(
        Request $request,
        FieldModel $fieldModel,
        CustomObjectModel $customObjectModel,
    ): JsonResponse {
        $text = (string) $request->request->get('text', $request->query->get('text', ''));

        $fields        = $fieldModel->getFieldList(false);
        $customObjects = $this->buildCustomObjectsList($customObjectModel);
        $variables     = $this->extractMappableVariables($text);

        return $this->sendJsonResponse(['success' => 1, 'variables' => $variables, 'fields' => $fields, 'customObjects' => $customObjects]);
    }

    /**
     * Split out of getEmailVariablesAction() so it's unit-testable without
     * the rest of that action's Symfony container dependency (sendJsonResponse()
     * needs a container, this doesn't).
     *
     * UnsubscribeVariable::KEY is always present in a saved template's
     * footer (EmailMirrorSyncSubscriber injects it there), but it's
     * resolved automatically by CampaignTriggerSubscriber on every
     * dispatch — never something a user maps by hand here, so it's
     * filtered out of the list the campaign builder shows.
     *
     * @return list<string>
     */
    private function extractMappableVariables(string $html): array
    {
        preg_match_all(self::VARIABLE_PATTERN, $html, $matches);

        return array_values(array_diff(array_unique($matches[1]), [UnsubscribeVariable::KEY]));
    }

    /**
     * Records who cancelled a pending n8n dispatch, from the Timeline
     * card's Cancel button (Assets/js/campaign-timeline-scheduled.js,
     * n8ndispatchConfirmCancelSchedule) — called right after core's own
     * campaign:cancelScheduledCampaignEvent, never instead of it. Core's
     * AjaxController::cancelScheduledCampaignEventAction (CampaignBundle)
     * only flips is_scheduled and writes a translated 'cancelled at' note
     * into metadata.errors; it has no concept of who clicked cancel, and
     * dispatches no event this could hook into instead — hence the
     * separate call rather than extending core's own endpoint.
     *
     * Same permission check and log lookup core's own endpoint uses
     * (LeadEventLogRepository has no dedicated finder for 'the current log
     * for this event+contact', so both resolve it the same way: latest by
     * dateTriggered).
     */
    public function recordScheduleCancellationAction(
        Request $request,
        UserHelper $userHelper,
        CorePermissions $security,
        \Mautic\CoreBundle\Twig\Helper\DateHelper $dateHelper
    ): JsonResponse {
        $eventId   = (int) $request->request->get('eventId');
        $contactId = (int) $request->request->get('contactId');

        if (empty($eventId) || empty($contactId)) {
            return $this->sendJsonResponse(['success' => 0]);
        }

        $contact = $this->getModel('lead')->getEntity($contactId);
        if (!$contact || !$security->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $contact->getPermissionUser())) {
            return $this->sendJsonResponse(['success' => 0]);
        }

        /** @var EventLogModel $logModel */
        $logModel = $this->getModel('campaign.event_log');
        $log      = $logModel->getRepository()->findOneBy(
            ['lead' => $contactId, 'event' => $eventId],
            ['dateTriggered' => 'desc']
        );

        if (!$log) {
            return $this->sendJsonResponse(['success' => 0]);
        }

        $user  = $userHelper->getUser(true);
        $email = $user instanceof \Mautic\UserBundle\Entity\User ? $user->getEmail() : null;

        $metadata = $log->getMetadata();
        // A distinct top-level key, not nested under 'n8ndispatch' — that
        // key is reserved for the actual dispatch request/response payload
        // (status, response, httpStatusCode, ... — written by
        // CampaignTriggerSubscriber::recordDispatchOutcome() and read back
        // by this same template's own 'n8n dispatch outcome' block below).
        // A cancelled-before-ever-dispatching event has no such payload;
        // reusing that key here made the template treat this cancellation
        // data as a dispatch outcome and crash reading n8n.status, which
        // never exists on it.
        $cancelledAt = (new \DateTime())->format('Y-m-d H:i:s');

        $metadata['n8ndispatch_cancellation']['cancelledByEmail'] = $email;
        $metadata['n8ndispatch_cancellation']['cancelledAt']      = $cancelledAt;
        $log->setMetadata($metadata);
        $logModel->getRepository()->saveEntity($log);

        // Pre-translated server-side (rather than handing the JS raw
        // email/date and a translation key) so the Timeline card's live
        // update doesn't need its own copy of 'mautic.n8ndispatch.
        // timeline.cancelled_by' registered in the 'javascript'
        // translation domain Mautic.translate() reads from — the Twig
        // side already renders this same key normally, on a later page
        // load. Same dateToFullConcat-equivalent formatting as the rest
        // of the app (DateHelper is core's own Twig helper, but it's a
        // plain service — nothing Twig-specific about calling it here).
        $cancelledByMessage = $email
            ? $this->translator->trans('mautic.n8ndispatch.timeline.cancelled_by', [
                '%email%' => $email,
                '%date%'  => $dateHelper->toFullConcat($cancelledAt),
            ])
            : null;

        return $this->sendJsonResponse(['success' => 1, 'cancelledByMessage' => $cancelledByMessage]);
    }

    /**
     * Serves the raw HTML snapshot CampaignTriggerSubscriber::
     * saveTemplateCopy() stores in Mautic core's own email_copies table
     * (via EmailModel::getCopyRepository(), the exact mechanism core's own
     * "view in browser" link uses) — resolved via action=plugin:
     * N8nDispatch:getTemplateCopy&hash=..., linked from the "View
     * template" link on the contact's Timeline card. Returns the content
     * as-is (no token substitution, unlike core's own webview), so this is
     * the raw template as configured, not what any one contact received.
     */
    public function getTemplateCopyAction(Request $request, EmailModel $emailModel): Response
    {
        $hash = (string) $request->query->get('hash', $request->request->get('hash', ''));
        $copy = '' !== $hash ? $emailModel->getCopyRepository()->find($hash) : null;

        if (null === $copy) {
            return new Response('Template snapshot not found.', Response::HTTP_NOT_FOUND);
        }

        $subject = (string) $copy->getSubject();
        $body    = (string) $copy->getBody();

        if (!str_contains($body, '<html')) {
            $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'
                .htmlspecialchars($subject, ENT_QUOTES)
                .'</title></head><body>'.$body.'</body></html>';
        }

        return new Response($body, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * @return array<string, array{label: string, fields: array<string, string>}>
     */
    private function buildCustomObjectsList(CustomObjectModel $customObjectModel): array
    {
        $result = [];

        foreach ($customObjectModel->fetchAllPublishedEntities() as $customObject) {
            $fields = [];
            foreach ($customObject->getCustomFields() as $field) {
                $fields[$field->getAlias()] = $field->getLabel();
            }

            $result[$customObject->getAlias()] = [
                'label'  => $customObject->getName(),
                'fields' => $fields,
            ];
        }

        return $result;
    }
}
