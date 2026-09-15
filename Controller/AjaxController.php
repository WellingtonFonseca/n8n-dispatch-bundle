<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CoreBundle\Controller\AjaxController as CommonAjaxController;
use Mautic\EmailBundle\Model\EmailModel;
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

    public function getEmailVariablesAction(Request $request, EmailModel $emailModel): JsonResponse
    {
        $emailId = (int) $request->request->get('emailId', $request->query->get('emailId', 0));

        if ($emailId <= 0) {
            return $this->sendJsonResponse(['success' => 1, 'variables' => []]);
        }

        $email = $emailModel->getEntity($emailId);

        if (null === $email) {
            return $this->sendJsonResponse(['success' => 0, 'variables' => []]);
        }

        preg_match_all(self::VARIABLE_PATTERN, (string) $email->getCustomHtml(), $matches);
        $variables = array_values(array_unique($matches[1]));

        return $this->sendJsonResponse(['success' => 1, 'variables' => $variables]);
    }
}
