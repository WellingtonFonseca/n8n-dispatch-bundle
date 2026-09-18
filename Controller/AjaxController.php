<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use MauticPlugin\N8nDispatchBundle\TrackingPixelVariable;
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
     * Split out of getEmailVariablesAction() so it's unit-testable without
     * the rest of that action's Symfony container dependency (sendJsonResponse()
     * needs a container, this doesn't).
     *
     * UnsubscribeVariable::KEY and TrackingPixelVariable::KEY are always
     * present in a saved template (EmailMirrorSyncSubscriber injects both —
     * the unsubscribe footer and the invisible tracking <img>), but both
     * are resolved automatically by CampaignTriggerSubscriber on every
     * dispatch — never something a user maps by hand here, so both are
     * filtered out of the list the campaign builder shows.
     *
     * @return list<string>
     */
    private function extractMappableVariables(string $html): array
    {
        preg_match_all(self::VARIABLE_PATTERN, $html, $matches);

        return array_values(array_diff(array_unique($matches[1]), [UnsubscribeVariable::KEY, TrackingPixelVariable::KEY]));
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
