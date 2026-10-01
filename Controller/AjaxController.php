<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Model\EventLogModel;
use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Twig\Helper\DateHelper;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\N8nDispatchBundle\Entity\EmailVariablesRepository;
use MauticPlugin\N8nDispatchBundle\Service\TemplateVariableScanner;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backs the "Variables" tab EventListener/EmailTabSubscriber.php adds to
 * the native Email edit page (getEmailVariablesAction/
 * saveEmailVariablesAction), plus the SMS/HSM Template forms
 * (getSmsVariablesAction/getHsmVariablesAction). The generic /ajax route
 * (Mautic\CoreBundle\Controller\AjaxController::delegateAjaxAction)
 * resolves action=plugin:N8nDispatch:<name> to <name>Action() below, by
 * bundle-name convention — no route registration needed.
 */
class AjaxController extends CommonAjaxController
{
    public function getEmailVariablesAction(
        Request $request,
        EmailModel $emailModel,
        FieldModel $fieldModel,
        CustomObjectModel $customObjectModel,
        TemplateVariableScanner $variableScanner,
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

        // 'html' is sent by email-tab-variables.js when the builder is
        // closed: the new content only lives in the page's textarea until
        // the Email form itself is saved, so the saved customHtml is stale.
        $html      = $request->request->has('html') ? (string) $request->request->get('html') : (string) $email->getCustomHtml();
        $variables = $variableScanner->extract($html);

        // The ones missing the n8n_ prefix, so the edit page can warn about them before the user tries to save.
        $invalidVariables = $variableScanner->extractWithoutPrefix($html);

        return $this->sendJsonResponse([
            'success'          => 1,
            'variables'        => $variables,
            'invalidVariables' => $invalidVariables,
            'fields'           => $fields,
            'customObjects'    => $customObjects,
        ]);
    }

    /**
     * Persists the "Variables" tab's variable-source rows to Entity/
     * EmailVariables.php. Called by Assets/js/email-tab-variables.js only
     * when the native Email form is actually submitted via Save/Apply —
     * not on every row edit, so opening the tab to look, or toggling a
     * source without meaning to keep it, never writes anything. This tab
     * isn't one of that Symfony Form's own fields (it can't be, Email is a
     * core entity), hence the separate AJAX call instead of just another
     * form field. EventListener/CampaignTriggerSubscriber.php reads this
     * back fresh at dispatch time.
     */
    public function saveEmailVariablesAction(
        Request $request,
        EmailVariablesRepository $emailVariablesRepository,
    ): JsonResponse {
        $emailId       = (int) $request->request->get('emailId', 0);
        $variablesJson = (string) $request->request->get('variablesJson', '{}');

        if ($emailId <= 0) {
            return $this->sendJsonResponse(['success' => 0]);
        }

        $decoded = json_decode($variablesJson, true);
        if (!is_array($decoded)) {
            return $this->sendJsonResponse(['success' => 0]);
        }

        $emailVariablesRepository->saveForEmail($emailId, $variablesJson);

        return $this->sendJsonResponse(['success' => 1]);
    }

    /**
     * Status (test/production/paused) of the given n8n dispatch campaign
     * events, read from event.properties.status. Backs the badge on the
     * campaign's read-only preview (Assets/js/campaign-preview-icons.js):
     * core's preview template doesn't render our own event template, and
     * the status isn't anywhere in its HTML. Events of other types, and
     * events of campaigns the user can't view, are left out.
     */
    public function getCampaignEventStatusesAction(Request $request, CorePermissions $security): JsonResponse
    {
        $eventIds = array_filter(array_map('intval', (array) $request->request->all('eventIds')));
        $model    = $this->getModel('campaign.event');
        $statuses = [];

        foreach ($eventIds as $eventId) {
            $event = $model->getEntity($eventId);

            if (!$event || 0 !== strpos((string) $event->getType(), 'n8ndispatch.')) {
                continue;
            }

            $campaign = $event->getCampaign();

            if (!$security->hasEntityAccess('campaign:campaigns:viewown', 'campaign:campaigns:viewother', $campaign->getCreatedBy())) {
                continue;
            }

            $statuses[$eventId] = $event->getProperties()['status'] ?? 'test';
        }

        return $this->sendJsonResponse(['success' => 1, 'statuses' => $statuses]);
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
        TemplateVariableScanner $variableScanner,
    ): JsonResponse {
        $text = (string) $request->request->get('text', $request->query->get('text', ''));

        $fields        = $fieldModel->getFieldList(false);
        $customObjects = $this->buildCustomObjectsList($customObjectModel);
        $variables     = $variableScanner->extract($text);

        // An SMS template only takes numeric placeholders ({{1}}, {{2}}, ...); the edit page warns about the
        // others as the text is typed, before the user tries to save.
        $nonNumericVariables = $variableScanner->findNonNumeric($text);

        return $this->sendJsonResponse([
            'success'             => 1,
            'variables'           => $variables,
            'nonNumericVariables' => $nonNumericVariables,
            'fields'              => $fields,
            'customObjects'       => $customObjects,
        ]);
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
        TemplateVariableScanner $variableScanner,
    ): JsonResponse {
        $text = (string) $request->request->get('text', $request->query->get('text', ''));

        $fields        = $fieldModel->getFieldList(false);
        $customObjects = $this->buildCustomObjectsList($customObjectModel);
        $variables     = $variableScanner->extract($text);

        // An HSM template only takes numeric placeholders ({{1}}, {{2}}, ...); the edit page warns about the
        // others as the text is typed, before the user tries to save.
        $nonNumericVariables = $variableScanner->findNonNumeric($text);

        return $this->sendJsonResponse([
            'success'             => 1,
            'variables'           => $variables,
            'nonNumericVariables' => $nonNumericVariables,
            'fields'              => $fields,
            'customObjects'       => $customObjects,
        ]);
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
     */
    public function recordScheduleCancellationAction(
        Request $request,
        UserHelper $userHelper,
        CorePermissions $security,
        DateHelper $dateHelper
    ): JsonResponse {
        $eventId   = (int) $request->request->get('eventId');
        $contactId = (int) $request->request->get('contactId');
        $log       = $this->resolveContactEventLog($eventId, $contactId, $security);

        if (!$log) {
            return $this->sendJsonResponse(['success' => 0]);
        }

        $email = $this->getUserEmail($userHelper);
        $at    = (new \DateTime())->format('Y-m-d H:i:s');

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
        $metadata['n8ndispatch_cancellation']['cancelledByEmail'] = $email;
        $metadata['n8ndispatch_cancellation']['cancelledAt']      = $at;
        $log->setMetadata($metadata);
        $this->getModel('campaign.event_log')->getRepository()->saveEntity($log);

        return $this->sendJsonResponse([
            'success'            => 1,
            'cancelledByMessage' => $this->translateAuditMessage('mautic.n8ndispatch.timeline.cancelled_by', $email, $at, $dateHelper),
        ]);
    }

    /**
     * Records who rescheduled a pending n8n dispatch to a new date, from
     * the Timeline card's reschedule flow (Assets/js/
     * campaign-timeline-scheduled.js) — a global jQuery ajaxSuccess
     * listener there, not a wrapped onclick like the cancellation button
     * above, since core lets a reschedule be saved two different ways
     * (pressing Enter in the inline date field, or our own Save button —
     * CampaignBundle/Assets/js/campaign.js's Mautic.updateScheduledCampaignEvent
     * and Mautic.saveScheduledCampaignEvent both fire the exact same
     * campaign:updateScheduledCampaignEvent ajax call under the hood, and
     * only one of the two goes through a button this plugin controls).
     * Same reasoning as the cancellation endpoint above for why this is a
     * separate call rather than touching core's own endpoint: core's
     * updateScheduledCampaignEventAction only moves trigger_date, no
     * concept of who asked for the move.
     */
    public function recordScheduleRescheduleAction(
        Request $request,
        UserHelper $userHelper,
        CorePermissions $security,
        DateHelper $dateHelper
    ): JsonResponse {
        $eventId   = (int) $request->request->get('eventId');
        $contactId = (int) $request->request->get('contactId');
        $log       = $this->resolveContactEventLog($eventId, $contactId, $security);

        if (!$log) {
            return $this->sendJsonResponse(['success' => 0]);
        }

        $email = $this->getUserEmail($userHelper);
        $at    = (new \DateTime())->format('Y-m-d H:i:s');

        $metadata                                                    = $log->getMetadata();
        $metadata['n8ndispatch_reschedule']['rescheduledByEmail'] = $email;
        $metadata['n8ndispatch_reschedule']['rescheduledAt']      = $at;
        $log->setMetadata($metadata);
        $this->getModel('campaign.event_log')->getRepository()->saveEntity($log);

        return $this->sendJsonResponse([
            'success'              => 1,
            'rescheduledByMessage' => $this->translateAuditMessage('mautic.n8ndispatch.timeline.rescheduled_by', $email, $at, $dateHelper),
        ]);
    }

    /**
     * Shared by recordScheduleCancellationAction and
     * recordScheduleRescheduleAction: same permission check and log lookup
     * core's own scheduled-event endpoints use (LeadEventLogRepository has
     * no dedicated finder for 'the current log for this event+contact', so
     * all three resolve it the same way — latest by dateTriggered).
     */
    private function resolveContactEventLog(int $eventId, int $contactId, CorePermissions $security): ?LeadEventLog
    {
        if (empty($eventId) || empty($contactId)) {
            return null;
        }

        $contact = $this->getModel('lead')->getEntity($contactId);
        if (!$contact || !$security->hasEntityAccess('lead:leads:editown', 'lead:leads:editother', $contact->getPermissionUser())) {
            return null;
        }

        /** @var EventLogModel $logModel */
        $logModel = $this->getModel('campaign.event_log');

        /** @var LeadEventLog|null $log */
        $log = $logModel->getRepository()->findOneBy(
            ['lead' => $contactId, 'event' => $eventId],
            ['dateTriggered' => 'desc']
        );

        return $log;
    }

    private function getUserEmail(UserHelper $userHelper): ?string
    {
        $user = $userHelper->getUser(true);

        return $user instanceof \Mautic\UserBundle\Entity\User ? $user->getEmail() : null;
    }

    /**
     * Pre-translated server-side (rather than handing the JS raw email/
     * date and a translation key) so the Timeline card's live update
     * doesn't need its own copy of these keys registered in the
     * 'javascript' translation domain Mautic.translate() reads from — the
     * Twig side already renders the same keys normally, on a later page
     * load. Same dateToFullConcat-equivalent formatting as the rest of the
     * app (DateHelper is core's own Twig helper, but it's a plain service
     * — nothing Twig-specific about calling it here).
     */
    private function translateAuditMessage(string $translationKey, ?string $email, string $at, DateHelper $dateHelper): ?string
    {
        if (!$email) {
            return null;
        }

        return $this->translator->trans($translationKey, [
            '%email%' => $email,
            '%date%'  => $dateHelper->toFullConcat($at),
        ]);
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
