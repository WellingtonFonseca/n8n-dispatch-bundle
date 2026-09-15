<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\CustomObjectsBundle\Model\CustomObjectModel;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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

        preg_match_all(self::VARIABLE_PATTERN, (string) $email->getCustomHtml(), $matches);
        $variables = array_values(array_unique($matches[1]));

        return $this->sendJsonResponse(['success' => 1, 'variables' => $variables, 'fields' => $fields, 'customObjects' => $customObjects]);
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
