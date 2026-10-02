<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Symfony\Component\HttpFoundation\Response;

/**
 * N8n Dispatch (main menu) > Disparos. For now only the page the menu item
 * opens; the list of dispatches and the resend action come next.
 */
class DispatchController extends CommonController
{
    public function indexAction(): Response
    {
        if (!$this->security->isGranted('plugin:plugins:manage')) {
            return $this->accessDenied();
        }

        return $this->delegateView([
            'viewParameters'  => [],
            'contentTemplate' => '@N8nDispatch/Dispatch/index.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_n8ndispatch.dispatch_index',
                'mauticContent' => 'n8ndispatch.dispatch',
                'route'         => $this->generateUrl('mautic_n8ndispatch.dispatch_index'),
            ],
        ]);
    }
}
